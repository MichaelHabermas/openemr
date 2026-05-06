<?php

/**
 * SQL-backed promotion of trusted AgentForge document facts into traceable OpenEMR records.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\AgentForge\Document\Promotion;

use OpenEMR\AgentForge\DatabaseExecutor;
use OpenEMR\AgentForge\Document\DocumentFact;
use OpenEMR\AgentForge\Document\DocumentFactClassifier;
use OpenEMR\AgentForge\Document\DocumentFactRepository;
use OpenEMR\AgentForge\Document\DocumentJob;
use OpenEMR\AgentForge\Document\DocumentJobId;
use OpenEMR\AgentForge\Document\Embedding\DocumentFactEmbeddingRepository;
use OpenEMR\AgentForge\Document\Embedding\EmbeddingProvider;
use OpenEMR\AgentForge\Document\Schema\Certainty;
use OpenEMR\AgentForge\Document\Schema\CertaintyClassifier;
use OpenEMR\AgentForge\Document\Schema\IntakeFormExtraction;
use OpenEMR\AgentForge\Document\Schema\IntakeFormFinding;
use OpenEMR\AgentForge\Document\Schema\LabPdfExtraction;
use OpenEMR\AgentForge\Document\Schema\LabResultRow;
use OpenEMR\AgentForge\Time\SystemPsrClock;
use Psr\Clock\ClockInterface;
use Throwable;

final readonly class SqlClinicalDocumentFactPromotionRepository implements ClinicalDocumentFactPromotionRepository
{
    private CertaintyClassifier $certaintyClassifier;
    private DocumentFactClassifier $documentFactClassifier;
    private ClinicalContentFingerprint $fingerprints;
    private ClockInterface $wallClock;
    private PromotionValueSerializer $serializer;
    private LabResultChartWriter $chartWriter;
    private PromotionLedgerWriter $ledger;

    public function __construct(
        private DatabaseExecutor $executor,
        private ?DocumentFactRepository $facts = null,
        private ?DocumentFactEmbeddingRepository $embeddings = null,
        private ?EmbeddingProvider $embeddingProvider = null,
        ?ClockInterface $wallClock = null,
    ) {
        $this->certaintyClassifier = new CertaintyClassifier();
        $this->documentFactClassifier = new DocumentFactClassifier($this->certaintyClassifier);
        $this->fingerprints = new ClinicalContentFingerprint();
        $this->wallClock = $wallClock ?? new SystemPsrClock();
        $this->serializer = new PromotionValueSerializer();
        $this->chartWriter = new LabResultChartWriter($executor, $this->serializer);
        $this->ledger = new PromotionLedgerWriter($executor, $this->serializer, $this->fingerprints, $this->wallClock);
    }

    public function promote(DocumentJob $job, LabPdfExtraction | IntakeFormExtraction $extraction): PromotionSummary
    {
        if ($job->id === null || !$this->trustedJob($job)) {
            return PromotionSummary::empty();
        }

        $promoted = 0;
        $needsReview = 0;
        $skipped = 0;
        if ($extraction instanceof LabPdfExtraction) {
            foreach ($extraction->results as $index => $row) {
                $status = $this->promoteLabRow($job, $row, sprintf('results[%d]', $index));
                match ($status) {
                    PromotionOutcome::Promoted->value => ++$promoted,
                    PromotionOutcome::NeedsReview->value,
                    PromotionOutcome::ConflictNeedsReview->value,
                    PromotionOutcome::NotPromotable->value => ++$needsReview,
                    default => ++$skipped,
                };
            }
        } else {
            foreach ($extraction->findings as $index => $finding) {
                $status = $this->promoteIntakeFinding($job, $finding, sprintf('findings[%d]', $index));
                match ($status) {
                    PromotionOutcome::Promoted->value => ++$promoted,
                    PromotionOutcome::NeedsReview->value,
                    PromotionOutcome::ConflictNeedsReview->value,
                    PromotionOutcome::NotPromotable->value => ++$needsReview,
                    default => ++$skipped,
                };
            }
        }

        return new PromotionSummary($promoted, $needsReview, $skipped);
    }

    private function trustedJob(DocumentJob $job): bool
    {
        if ($job->id === null) {
            return false;
        }

        $rows = $this->executor->fetchRecords(
            'SELECT j.id '
            . 'FROM clinical_document_processing_jobs j '
            . 'INNER JOIN clinical_document_identity_checks ic ON ic.job_id = j.id '
            . 'INNER JOIN documents d ON d.id = j.document_id '
            . 'WHERE j.id = ? '
            . 'AND j.patient_id = ? '
            . 'AND j.document_id = ? '
            . 'AND j.status IN (?, ?) '
            . 'AND j.retracted_at IS NULL '
            . 'AND (ic.identity_status IN (?, ?) OR ic.review_decision = ?) '
            . 'AND (ic.review_required = 0 OR ic.review_decision = ?) '
            . 'AND (d.deleted IS NULL OR d.deleted = 0) '
            . 'LIMIT 1',
            [
                $job->id->value,
                $job->patientId->value,
                $job->documentId->value,
                'pending',
                'running',
                'identity_verified',
                'identity_review_approved',
                'approved',
                'approved',
            ],
        );

        return $rows !== [];
    }

    private function promoteLabRow(DocumentJob $job, LabResultRow $row, string $fieldPath): string
    {
        if ($job->id === null) {
            return PromotionOutcome::PromotionFailed->value;
        }

        $stableValue = $this->serializer->stableLabValueJson($row);
        $clinicalContentFingerprint = $this->fingerprints->patientClinicalFingerprint('lab_result', $row->testName, $stableValue);
        $legacyFactHash = $this->serializer->legacyFactHash('lab_result', $row->testName, $stableValue);
        $factFingerprint = $this->fingerprints->sourceFactFingerprint($job, 'lab_result', $fieldPath, $stableValue);
        $collectedAt = $this->serializer->dateTimeOrNull($row->collectedAt);
        $certainty = $this->documentFactClassifier->classify($job, $row);
        if (
            $certainty !== Certainty::Verified
            || $row->testName === ''
            || $row->value === ''
            || $collectedAt === null
        ) {
            $this->persistLabFact($job, $row, $fieldPath, $certainty, $factFingerprint, $clinicalContentFingerprint);

            return $this->ledger->upsertLedger(
                $job,
                'lab_result',
                $fieldPath,
                $row->testName,
                $this->serializer->labValueJson($row),
                $row->citation,
                PromotionOutcome::NeedsReview,
                null,
                null,
                null,
                $factFingerprint,
                $clinicalContentFingerprint,
                $row->confidence,
                $collectedAt === null ? 'missing_or_invalid_collected_at' : null,
            );
        }

        return $this->withPromotionLock($job, $clinicalContentFingerprint, function () use ($job, $row, $fieldPath, $factFingerprint, $clinicalContentFingerprint, $legacyFactHash, $collectedAt): string {
            $this->persistLabFact($job, $row, $fieldPath, Certainty::Verified, $factFingerprint, $clinicalContentFingerprint);
            $jobId = $job->id->value;

            $existing = $this->ledger->existingPromotedFact($job, $clinicalContentFingerprint, $legacyFactHash);
            $existingStatus = $this->ledger->statusForExistingFact(
                $existing,
                $jobId,
                $job,
                'lab_result',
                $fieldPath,
                $row->testName,
                $this->serializer->labValueJson($row),
                $row->citation,
                $factFingerprint,
                $clinicalContentFingerprint,
                $row->confidence,
            );
            if ($existingStatus !== null) {
                return $existingStatus;
            }

            $chartMatch = $this->chartWriter->existingChartLabMatch($job, $row);
            if ($chartMatch !== []) {
                $alreadyExists = $this->chartWriter->sameLabValue($chartMatch, $row);
                return $this->ledger->upsertLedger(
                    $job,
                    'lab_result',
                    $fieldPath,
                    $row->testName,
                    $this->serializer->labValueJson($row),
                    $row->citation,
                    $alreadyExists ? PromotionOutcome::AlreadyExists : PromotionOutcome::ConflictNeedsReview,
                    'procedure_result',
                    $this->serializer->nullableString($chartMatch, 'procedure_result_id'),
                    $this->serializer->json(['procedure_result_id' => $this->serializer->nullableString($chartMatch, 'procedure_result_id')]),
                    $factFingerprint,
                    $clinicalContentFingerprint,
                    $row->confidence,
                    $alreadyExists ? null : 'existing_chart_row_conflict',
                );
            }

            $resultId = $this->chartWriter->writeLabResult($job, $row, $clinicalContentFingerprint, $collectedAt, $this->now());

            return $this->ledger->upsertLedger(
                $job,
                'lab_result',
                $fieldPath,
                $row->testName,
                $this->serializer->labValueJson($row),
                $row->citation,
                PromotionOutcome::Promoted,
                'procedure_result',
                (string) $resultId,
                $this->serializer->json(['procedure_result_id' => (string) $resultId]),
                $factFingerprint,
                $clinicalContentFingerprint,
                $row->confidence,
            );
        });
    }

    private function promoteIntakeFinding(DocumentJob $job, IntakeFormFinding $finding, string $fieldPath): string
    {
        if ($job->id === null) {
            return PromotionOutcome::PromotionFailed->value;
        }

        $stableValue = $this->serializer->stableFindingValueJson($finding);
        $clinicalContentFingerprint = $this->fingerprints->patientClinicalFingerprint('intake_finding', $finding->field, $stableValue);
        $factFingerprint = $this->fingerprints->sourceFactFingerprint($job, 'intake_finding', $fieldPath, $stableValue);
        $certainty = $this->documentFactClassifier->classify($job, $finding);
        $this->persistIntakeFact($job, $finding, $fieldPath, $certainty, $factFingerprint, $clinicalContentFingerprint);
        $needsReview = $certainty === Certainty::NeedsReview;

        return $this->ledger->upsertLedger(
            $job,
            'intake_finding',
            $fieldPath,
            $finding->field,
            $this->serializer->findingValueJson($finding),
            $finding->citation,
            $needsReview ? PromotionOutcome::NeedsReview : PromotionOutcome::NotPromotable,
            null,
            null,
            null,
            $factFingerprint,
            $clinicalContentFingerprint,
            $finding->confidence,
            $needsReview ? null : 'no_safe_native_destination',
        );
    }

    private function persistLabFact(
        DocumentJob $job,
        LabResultRow $row,
        string $fieldPath,
        Certainty $certainty,
        string $factFingerprint,
        string $clinicalContentFingerprint,
    ): void {
        if ($this->facts === null || $job->id === null) {
            return;
        }

        $valueJson = $this->serializer->labValueJson($row) + ['field_path' => $fieldPath];
        $textParts = array_filter([
            $row->testName,
            $row->value . ($row->unit !== '' ? ' ' . $row->unit : ''),
            $row->referenceRange !== '' ? 'reference range: ' . $row->referenceRange : '',
            'abnormal: ' . $row->abnormalFlag->value,
        ]);
        $factText = implode('; ', $textParts);
        $factId = $this->facts->upsert(new DocumentFact(
            null,
            $job->patientId,
            $job->documentId,
            new DocumentJobId($job->id->value),
            $job->docType,
            'lab_result',
            $certainty->value,
            $factFingerprint,
            $clinicalContentFingerprint,
            $factText,
            $valueJson,
            $this->serializer->citationJson($row->citation),
            $row->confidence,
            $this->documentFactClassifier->promotionStatus($certainty),
        ));
        $this->embedFact($factId, $factText, $certainty);
    }

    private function persistIntakeFact(
        DocumentJob $job,
        IntakeFormFinding $finding,
        string $fieldPath,
        Certainty $certainty,
        string $factFingerprint,
        string $clinicalContentFingerprint,
    ): void {
        if ($this->facts === null || $job->id === null) {
            return;
        }

        $factId = $this->facts->upsert(new DocumentFact(
            null,
            $job->patientId,
            $job->documentId,
            new DocumentJobId($job->id->value),
            $job->docType,
            'intake_finding',
            $certainty->value,
            $factFingerprint,
            $clinicalContentFingerprint,
            $finding->value,
            $this->serializer->findingValueJson($finding) + ['field_path' => $fieldPath],
            $this->serializer->citationJson($finding->citation),
            $finding->confidence,
            $this->documentFactClassifier->promotionStatus($certainty),
        ));
        $this->embedFact($factId, $finding->value, $certainty);
    }

    private function embedFact(int $factId, string $factText, Certainty $certainty): void
    {
        if (
            $factId <= 0
            || $certainty === Certainty::NeedsReview
            || $this->embeddings === null
            || $this->embeddingProvider === null
        ) {
            return;
        }

        $this->embeddings->upsert($factId, $factText, $this->embeddingProvider);
    }

    /**
     * @param callable(): string $callback
     */
    private function withPromotionLock(DocumentJob $job, string $clinicalContentFingerprint, callable $callback): string
    {
        $lockName = sprintf('agentforge-fact:%d:%s', $job->patientId->value, $clinicalContentFingerprint);
        $rows = $this->executor->fetchRecords('SELECT GET_LOCK(?, 10) AS acquired', [$lockName]);
        if (!$this->lockAcquired($rows[0]['acquired'] ?? null)) {
            return PromotionOutcome::PromotionFailed->value;
        }

        try {
            $this->executor->executeStatement('START TRANSACTION');
            try {
                $result = $callback();
                $this->executor->executeStatement('COMMIT');

                return $result;
            } catch (Throwable $throwable) {
                $this->executor->executeStatement('ROLLBACK');

                throw $throwable;
            }
        } finally {
            $this->executor->fetchRecords('SELECT RELEASE_LOCK(?) AS released', [$lockName]);
        }
    }

    private function lockAcquired(mixed $value): bool
    {
        return $value === 1 || $value === '1';
    }

    private function now(): string
    {
        return $this->wallClock->now()->format('Y-m-d H:i:s');
    }
}
