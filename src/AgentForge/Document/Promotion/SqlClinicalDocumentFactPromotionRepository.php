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

use DateTimeImmutable;
use OpenEMR\AgentForge\DatabaseExecutor;
use OpenEMR\AgentForge\Document\DocumentFact;
use OpenEMR\AgentForge\Document\DocumentFactClassifier;
use OpenEMR\AgentForge\Document\DocumentFactRepository;
use OpenEMR\AgentForge\Document\DocumentJob;
use OpenEMR\AgentForge\Document\DocumentJobId;
use OpenEMR\AgentForge\Document\Embedding\DocumentFactEmbeddingRepository;
use OpenEMR\AgentForge\Document\Embedding\EmbeddingProvider;
use OpenEMR\AgentForge\Document\JobStatus;
use OpenEMR\AgentForge\Document\Schema\BoundingBox;
use OpenEMR\AgentForge\Document\Schema\Certainty;
use OpenEMR\AgentForge\Document\Schema\CertaintyClassifier;
use OpenEMR\AgentForge\Document\Schema\ClinicalWorkbookExtraction;
use OpenEMR\AgentForge\Document\Schema\DocumentCitation;
use OpenEMR\AgentForge\Document\Schema\ExtractedClinicalFact;
use OpenEMR\AgentForge\Document\Schema\FaxPacketExtraction;
use OpenEMR\AgentForge\Document\Schema\Hl7v2MessageExtraction;
use OpenEMR\AgentForge\Document\Schema\IntakeFormExtraction;
use OpenEMR\AgentForge\Document\Schema\IntakeFormFinding;
use OpenEMR\AgentForge\Document\Schema\LabPdfExtraction;
use OpenEMR\AgentForge\Document\Schema\LabResultRow;
use OpenEMR\AgentForge\Document\Schema\ReferralDocxExtraction;
use OpenEMR\AgentForge\Document\TrustedDocumentGate;
use OpenEMR\AgentForge\Time\SystemPsrClock;
use Psr\Clock\ClockInterface;
use Throwable;

final readonly class SqlClinicalDocumentFactPromotionRepository implements ClinicalDocumentFactPromotionRepository
{
    private CertaintyClassifier $certaintyClassifier;
    private DocumentFactClassifier $documentFactClassifier;
    private ClockInterface $wallClock;

    public function __construct(
        private DatabaseExecutor $executor,
        private ?DocumentFactRepository $facts = null,
        private ?DocumentFactEmbeddingRepository $embeddings = null,
        private ?EmbeddingProvider $embeddingProvider = null,
        private TrustedDocumentGate $trustedDocuments = new TrustedDocumentGate(),
        private PromotionFingerprinter $fingerprinter = new PromotionFingerprinter(),
        ?ClockInterface $wallClock = null,
    ) {
        $this->certaintyClassifier = new CertaintyClassifier();
        $this->documentFactClassifier = new DocumentFactClassifier($this->certaintyClassifier);
        $this->wallClock = $wallClock ?? new SystemPsrClock();
    }

    public function promote(DocumentJob $job, LabPdfExtraction | IntakeFormExtraction | ReferralDocxExtraction | ClinicalWorkbookExtraction | FaxPacketExtraction | Hl7v2MessageExtraction $extraction): PromotionSummary
    {
        if ($job->id === null || !$this->trustedJob($job)) {
            return PromotionSummary::empty();
        }

        $promoted = 0;
        $needsReview = 0;
        $skipped = 0;
        if ($extraction instanceof LabPdfExtraction) {
            foreach ($extraction->results as $index => $row) {
                $outcome = $this->promoteLabRow($job, $row, sprintf('results[%d]', $index));
                match ($outcome) {
                    PromotionOutcome::Promoted => ++$promoted,
                    PromotionOutcome::NeedsReview,
                    PromotionOutcome::ConflictNeedsReview,
                    PromotionOutcome::NotPromotable => ++$needsReview,
                    PromotionOutcome::AlreadyExists,
                    PromotionOutcome::DuplicateSkipped,
                    PromotionOutcome::Rejected,
                    PromotionOutcome::PromotionFailed,
                    PromotionOutcome::Retracted => ++$skipped,
                };
            }
        } elseif ($extraction instanceof IntakeFormExtraction) {
            foreach ($extraction->findings as $index => $finding) {
                $outcome = $this->promoteIntakeFinding($job, $finding, sprintf('findings[%d]', $index));
                match ($outcome) {
                    PromotionOutcome::Promoted => ++$promoted,
                    PromotionOutcome::NeedsReview,
                    PromotionOutcome::ConflictNeedsReview,
                    PromotionOutcome::NotPromotable => ++$needsReview,
                    PromotionOutcome::AlreadyExists,
                    PromotionOutcome::DuplicateSkipped,
                    PromotionOutcome::Rejected,
                    PromotionOutcome::PromotionFailed,
                    PromotionOutcome::Retracted => ++$skipped,
                };
            }
        } else {
            foreach ($extraction->facts as $index => $fact) {
                $certainty = $this->documentFactClassifier->classify($job, $fact);
                $this->persistGenericFact($job, $fact, $fact->fieldPath !== '' ? $fact->fieldPath : sprintf('facts[%d]', $index), $certainty);
                if ($certainty === Certainty::NeedsReview) {
                    ++$needsReview;
                } else {
                    ++$skipped;
                }
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
            . $this->trustedDocuments->where(statuses: [JobStatus::Pending, JobStatus::Running])
            . 'LIMIT 1',
            [
                $job->id->value,
                $job->patientId->value,
                $job->documentId->value,
                ...$this->trustedDocuments->binds([JobStatus::Pending, JobStatus::Running]),
            ],
        );

        return $rows !== [];
    }

    private function promoteLabRow(DocumentJob $job, LabResultRow $row, string $fieldPath): PromotionOutcome
    {
        if ($job->id === null) {
            return PromotionOutcome::PromotionFailed;
        }

        $stableValue = $this->stableLabValueJson($row);
        $clinicalContentFingerprint = $this->fingerprinter->patientClinicalFingerprint('lab_result', $row->testName, $stableValue);
        $legacyFactHash = $this->fingerprinter->legacyFactHash('lab_result', $row->testName, $stableValue);
        $factFingerprint = $this->fingerprinter->sourceFactFingerprint($job, 'lab_result', $fieldPath, $stableValue);
        $collectedAt = $this->dateTimeOrNull($row->collectedAt);
        $certainty = $this->documentFactClassifier->classify($job, $row);
        if (
            $certainty !== Certainty::Verified
            || $row->testName === ''
            || $row->value === ''
            || $collectedAt === null
        ) {
            $this->persistLabFact($job, $row, $fieldPath, $certainty, $factFingerprint, $clinicalContentFingerprint);

            return $this->upsertLedger(new PromotionRecord(
                $job,
                'lab_result',
                $fieldPath,
                $row->testName,
                $this->labValueJson($row),
                $row->citation,
                PromotionOutcome::NeedsReview,
                null,
                null,
                null,
                $factFingerprint,
                $clinicalContentFingerprint,
                $row->confidence,
                $collectedAt === null ? 'missing_or_invalid_collected_at' : null,
            ));
        }

        return $this->withPromotionLock($job, $clinicalContentFingerprint, function () use ($job, $row, $fieldPath, $factFingerprint, $clinicalContentFingerprint, $legacyFactHash, $collectedAt): PromotionOutcome {
            $this->persistLabFact($job, $row, $fieldPath, Certainty::Verified, $factFingerprint, $clinicalContentFingerprint);
            $jobId = $job->id->value;

            $existing = $this->existingPromotedFact($job, $clinicalContentFingerprint, $legacyFactHash);
            $existingStatus = $this->statusForExistingFact(
                $existing,
                $jobId,
                $job,
                'lab_result',
                $fieldPath,
                $row->testName,
                $this->labValueJson($row),
                $row->citation,
                $factFingerprint,
                $clinicalContentFingerprint,
                $row->confidence,
            );
            if ($existingStatus !== null) {
                return $existingStatus;
            }

            $chartMatch = $this->existingChartLabMatch($job, $row);
            if ($chartMatch !== []) {
                $alreadyExists = $this->sameLabValue($chartMatch, $row);
                return $this->upsertLedger(new PromotionRecord(
                    $job,
                    'lab_result',
                    $fieldPath,
                    $row->testName,
                    $this->labValueJson($row),
                    $row->citation,
                    $alreadyExists ? PromotionOutcome::AlreadyExists : PromotionOutcome::ConflictNeedsReview,
                    'procedure_result',
                    $this->nullableString($chartMatch, 'procedure_result_id'),
                    $this->jsonEncode(['procedure_result_id' => $this->nullableString($chartMatch, 'procedure_result_id')]),
                    $factFingerprint,
                    $clinicalContentFingerprint,
                    $row->confidence,
                    $alreadyExists ? null : 'existing_chart_row_conflict',
                ));
            }

            $resultId = $this->writeLabResult($job, $row, $clinicalContentFingerprint, $collectedAt, $this->now());

            return $this->upsertLedger(new PromotionRecord(
                $job,
                'lab_result',
                $fieldPath,
                $row->testName,
                $this->labValueJson($row),
                $row->citation,
                PromotionOutcome::Promoted,
                'procedure_result',
                (string) $resultId,
                $this->jsonEncode(['procedure_result_id' => (string) $resultId]),
                $factFingerprint,
                $clinicalContentFingerprint,
                $row->confidence,
            ));
        });
    }

    private function promoteIntakeFinding(DocumentJob $job, IntakeFormFinding $finding, string $fieldPath): PromotionOutcome
    {
        if ($job->id === null) {
            return PromotionOutcome::PromotionFailed;
        }

        $stableValue = $this->stableFindingValueJson($finding);
        $clinicalContentFingerprint = $this->fingerprinter->patientClinicalFingerprint('intake_finding', $finding->field, $stableValue);
        $factFingerprint = $this->fingerprinter->sourceFactFingerprint($job, 'intake_finding', $fieldPath, $stableValue);
        $certainty = $this->documentFactClassifier->classify($job, $finding);
        $this->persistIntakeFact($job, $finding, $fieldPath, $certainty, $factFingerprint, $clinicalContentFingerprint);
        $needsReview = $certainty === Certainty::NeedsReview;

        return $this->upsertLedger(new PromotionRecord(
            $job,
            'intake_finding',
            $fieldPath,
            $finding->field,
            $this->findingValueJson($finding),
            $finding->citation,
            $needsReview ? PromotionOutcome::NeedsReview : PromotionOutcome::NotPromotable,
            null,
            null,
            null,
            $factFingerprint,
            $clinicalContentFingerprint,
            $finding->confidence,
            $needsReview ? null : 'no_safe_native_destination',
        ));
    }

    // ── Ledger persistence ─────────────────────────────────────────────────

    private function upsertLedger(PromotionRecord $record): PromotionOutcome
    {
        $now = $this->now();
        $this->executor->executeStatement(
            'INSERT INTO clinical_document_promotions '
            . '(patient_id, document_id, job_id, fact_id, doc_type, fact_type, field_path, display_label, value_json, '
            . 'fact_fingerprint, clinical_content_fingerprint, promoted_table, promoted_record_id, promoted_pk_json, '
            . 'outcome, duplicate_key, conflict_reason, citation_json, bounding_box_json, confidence, review_status, active, created_at, updated_at) '
            . 'VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, ?, ?) '
            . 'ON DUPLICATE KEY UPDATE promoted_table = VALUES(promoted_table), promoted_record_id = VALUES(promoted_record_id), '
            . 'promoted_pk_json = VALUES(promoted_pk_json), outcome = VALUES(outcome), duplicate_key = VALUES(duplicate_key), '
            . 'conflict_reason = VALUES(conflict_reason), confidence = VALUES(confidence), review_status = VALUES(review_status), '
            . 'active = VALUES(active), updated_at = VALUES(updated_at)',
            [
                $record->job->patientId->value,
                $record->job->documentId->value,
                $record->job->id?->value,
                $record->factFingerprint,
                $record->job->docType->value,
                $record->factType,
                $record->fieldPath,
                $record->label,
                $this->jsonEncode($record->valueJson),
                $record->factFingerprint,
                $record->clinicalContentFingerprint,
                $record->promotedTable ?? '',
                $record->promotedRecordId,
                $record->promotedPkJson,
                $record->outcome->value,
                $record->clinicalContentFingerprint,
                $record->conflictReason,
                $this->jsonEncode($this->citationJson($record->citation)),
                $record->citation->boundingBox === null ? null : $this->jsonEncode($this->boundingBoxJson($record->citation->boundingBox)),
                $record->confidence,
                $record->outcome->reviewStatus(),
                $now,
                $now,
            ],
        );

        return $record->outcome;
    }

    /** @return array<string, mixed> */
    private function existingPromotedFact(DocumentJob $job, string $clinicalContentFingerprint, string $legacyFactHash): array
    {
        $rows = $this->executor->fetchRecords(
            'SELECT job_id, outcome, promoted_table, promoted_record_id, promoted_pk_json '
            . 'FROM clinical_document_promotions '
            . 'WHERE patient_id = ? AND clinical_content_fingerprint = ? AND outcome = ? AND active = 1 '
            . 'ORDER BY id ASC LIMIT 1',
            [$job->patientId->value, $clinicalContentFingerprint, PromotionOutcome::Promoted->value],
        );
        if ($rows !== []) {
            return $rows[0];
        }

        $legacyRows = $this->executor->fetchRecords(
            'SELECT job_id, promotion_status AS outcome, native_table AS promoted_table, native_id AS promoted_record_id, '
            . 'JSON_OBJECT("legacy_native_id", native_id) AS promoted_pk_json '
            . 'FROM clinical_document_promoted_facts '
            . 'WHERE patient_id = ? AND fact_hash = ? AND promotion_status = ? '
            . 'ORDER BY id ASC LIMIT 1',
            [$job->patientId->value, $legacyFactHash, PromotionOutcome::Promoted->value],
        );

        return $legacyRows[0] ?? [];
    }

    /**
     * @param array<string, mixed> $existing
     * @param array<string, mixed> $valueJson
     */
    private function statusForExistingFact(
        array $existing,
        int $jobId,
        DocumentJob $job,
        string $factType,
        string $fieldPath,
        string $label,
        array $valueJson,
        DocumentCitation $citation,
        string $factFingerprint,
        string $clinicalContentFingerprint,
        float $confidence,
    ): ?PromotionOutcome {
        if ($existing === []) {
            return null;
        }
        if ($this->intValue($existing, 'job_id') === $jobId) {
            return PromotionOutcome::DuplicateSkipped;
        }

        return $this->upsertLedger(new PromotionRecord(
            $job,
            $factType,
            $fieldPath,
            $label,
            $valueJson,
            $citation,
            PromotionOutcome::DuplicateSkipped,
            $this->nullableString($existing, 'promoted_table'),
            $this->nullableString($existing, 'promoted_record_id'),
            $this->nullableString($existing, 'promoted_pk_json'),
            $factFingerprint,
            $clinicalContentFingerprint,
            $confidence,
        ));
    }

    // ── Chart writing ──────────────────────────────────────────────────────

    /** @return array<string, mixed> */
    private function existingChartLabMatch(DocumentJob $job, LabResultRow $row): array
    {
        $collectedDate = substr($row->collectedAt, 0, 10);
        if ($row->testName === '' || $row->value === '' || $collectedDate === '') {
            return [];
        }

        $rows = $this->executor->fetchRecords(
            'SELECT pr.procedure_result_id, pr.result, pr.units '
            . 'FROM procedure_result pr '
            . 'INNER JOIN procedure_report prep ON prep.procedure_report_id = pr.procedure_report_id '
            . 'INNER JOIN procedure_order po ON po.procedure_order_id = prep.procedure_order_id '
            . 'WHERE po.patient_id = ? '
            . 'AND pr.result_text = ? '
            . 'AND DATE(pr.date) = ? '
            . 'AND (pr.document_id IS NULL OR pr.document_id <> ?) '
            . 'LIMIT 1',
            [$job->patientId->value, $row->testName, $collectedDate, $job->documentId->value],
        );

        return $rows[0] ?? [];
    }

    /** @param array<string, mixed> $row */
    private function sameLabValue(array $row, LabResultRow $extracted): bool
    {
        return $this->normalizeScalar($this->nullableString($row, 'result')) === $this->normalizeScalar($extracted->value)
            && $this->normalizeScalar($this->nullableString($row, 'units')) === $this->normalizeScalar($extracted->unit);
    }

    private function writeLabResult(
        DocumentJob $job,
        LabResultRow $row,
        string $clinicalContentFingerprint,
        string $collectedAt,
        string $now,
    ): int {
        $orderId = $this->executor->insert(
            'INSERT INTO procedure_order '
            . '(provider_id, patient_id, date_collected, date_ordered, order_status, activity, control_id, history_order, procedure_order_type, order_intent) '
            . 'VALUES (?, ?, ?, ?, ?, 1, ?, ?, ?, ?)',
            [
                0,
                $job->patientId->value,
                $collectedAt,
                $now,
                'complete',
                sprintf('agentforge-doc-%d', $job->documentId->value),
                '1',
                'laboratory_test',
                'order',
            ],
        );
        $this->executor->executeStatement(
            'INSERT INTO procedure_order_code '
            . '(procedure_order_id, procedure_order_seq, procedure_code, procedure_name, procedure_source, procedure_order_title, procedure_type) '
            . 'VALUES (?, 1, ?, ?, ?, ?, ?)',
            [
                $orderId,
                substr('agentforge-' . $clinicalContentFingerprint, 0, 31),
                $row->testName,
                '1',
                $row->testName,
                'laboratory_test',
            ],
        );
        $reportId = $this->executor->insert(
            'INSERT INTO procedure_report '
            . '(procedure_order_id, procedure_order_seq, date_collected, date_report, source, specimen_num, report_status, review_status, report_notes) '
            . 'VALUES (?, 1, ?, ?, 0, ?, ?, ?, ?)',
            [
                $orderId,
                $collectedAt,
                $now,
                $job->id?->value,
                'complete',
                'reviewed',
                'AgentForge promoted from verified clinical document.',
            ],
        );

        return $this->executor->insert(
            'INSERT INTO procedure_result '
            . '(procedure_report_id, result_data_type, result_text, date, facility, units, result, `range`, abnormal, comments, document_id, result_status) '
            . 'VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [
                $reportId,
                is_numeric($row->value) ? 'N' : 'S',
                $row->testName,
                $collectedAt,
                'AgentForge Document Extraction',
                $row->unit,
                $row->value,
                $row->referenceRange,
                $row->abnormalFlag->value,
                sprintf('agentforge-fact:%s', $clinicalContentFingerprint),
                $job->documentId->value,
                'final',
            ],
        );
    }

    // ── Fact persistence ───────────────────────────────────────────────────

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

        $valueJson = $this->labValueJson($row) + ['field_path' => $fieldPath];
        $textParts = array_filter([
            $row->testName,
            $this->displayLabValue($row),
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
            $this->citationJson($row->citation),
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
            $this->findingValueJson($finding) + ['field_path' => $fieldPath],
            $this->citationJson($finding->citation),
            $finding->confidence,
            $this->documentFactClassifier->promotionStatus($certainty),
        ));
        $this->embedFact($factId, $finding->value, $certainty);
    }

    private function persistGenericFact(
        DocumentJob $job,
        ExtractedClinicalFact $fact,
        string $fieldPath,
        Certainty $certainty,
    ): void {
        if ($this->facts === null || $job->id === null) {
            return;
        }

        $valueJson = $this->genericValueJson($fact) + ['field_path' => $fieldPath];
        $clinicalContentFingerprint = $this->fingerprinter->patientClinicalFingerprint($fact->type, $fact->label, $valueJson);
        $factFingerprint = $this->fingerprinter->sourceFactFingerprint($job, $fact->type, $fieldPath, $valueJson);
        $factText = trim($fact->label . ': ' . $fact->value);
        if ($factText === ':') {
            $factText = $fieldPath;
        }

        $factId = $this->facts->upsert(new DocumentFact(
            null,
            $job->patientId,
            $job->documentId,
            new DocumentJobId($job->id->value),
            $job->docType,
            $fact->type,
            $certainty->value,
            $factFingerprint,
            $clinicalContentFingerprint,
            $factText,
            $valueJson,
            $this->citationJson($fact->citation),
            $fact->confidence,
            $this->documentFactClassifier->promotionStatus($certainty),
        ));
        $this->embedFact($factId, $factText, $certainty);
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

    // ── Serialization ──────────────────────────────────────────────────────

    /** @return array<string, mixed> */
    private function labValueJson(LabResultRow $row): array
    {
        return [
            'test_name' => $row->testName,
            'value' => $row->value,
            'unit' => $row->unit,
            'reference_range' => $row->referenceRange,
            'collected_at' => $row->collectedAt,
            'abnormal_flag' => $row->abnormalFlag->value,
            'certainty' => $row->certainty->value,
            'confidence' => $row->confidence,
        ];
    }

    private function displayLabValue(LabResultRow $row): string
    {
        if ($row->unit === '' || str_ends_with(strtolower($row->value), strtolower(' ' . $row->unit))) {
            return $row->value;
        }

        return $row->value . ' ' . $row->unit;
    }

    /** @return array<string, mixed> */
    private function findingValueJson(IntakeFormFinding $finding): array
    {
        return [
            'field' => $finding->field,
            'value' => $finding->value,
            'certainty' => $finding->certainty->value,
            'confidence' => $finding->confidence,
        ];
    }

    /** @return array<string, mixed> */
    private function stableLabValueJson(LabResultRow $row): array
    {
        return [
            'test_name' => strtolower($row->testName),
            'value' => $row->value,
            'unit' => strtolower($row->unit),
            'reference_range' => $row->referenceRange,
            'collected_at' => substr($row->collectedAt, 0, 10),
            'abnormal_flag' => $row->abnormalFlag->value,
        ];
    }

    /** @return array<string, mixed> */
    private function stableFindingValueJson(IntakeFormFinding $finding): array
    {
        return [
            'field' => strtolower($finding->field),
            'value' => strtolower($finding->value),
        ];
    }

    /** @return array<string, mixed> */
    private function genericValueJson(ExtractedClinicalFact $fact): array
    {
        return [
            'type' => $fact->type,
            'label' => $fact->label,
            'value' => $fact->value,
            'certainty' => $fact->certainty->value,
            'confidence' => $fact->confidence,
        ];
    }

    /** @return array<string, mixed> */
    private function citationJson(DocumentCitation $citation): array
    {
        return [
            'source_type' => $citation->sourceType->value,
            'source_id' => $citation->sourceId,
            'page_or_section' => $citation->pageOrSection,
            'field_or_chunk_id' => $citation->fieldOrChunkId,
            'quote_or_value' => $citation->quoteOrValue,
            'bounding_box' => $citation->boundingBox === null ? null : $this->boundingBoxJson($citation->boundingBox),
        ];
    }

    /** @return array<string, float> */
    private function boundingBoxJson(BoundingBox $box): array
    {
        return ['x' => $box->x, 'y' => $box->y, 'width' => $box->width, 'height' => $box->height];
    }

    /** @param array<string, mixed> $data */
    private function jsonEncode(array $data): string
    {
        return json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    }

    private function dateTimeOrNull(string $value): ?string
    {
        if (preg_match('/^\d{4}-\d{2}-\d{2}/', $value) !== 1) {
            return null;
        }

        return (new DateTimeImmutable($value))->format('Y-m-d H:i:s');
    }

    private function normalizeScalar(?string $value): string
    {
        return strtolower(trim((string) $value));
    }

    /** @param array<string, mixed> $row */
    private function nullableString(array $row, string $key): ?string
    {
        $value = $row[$key] ?? null;
        return is_scalar($value) && trim((string) $value) !== '' ? (string) $value : null;
    }

    /** @param array<string, mixed> $row */
    private function intValue(array $row, string $key): ?int
    {
        $value = $row[$key] ?? null;
        if (is_int($value)) {
            return $value;
        }
        if (is_string($value) && ctype_digit($value)) {
            return (int) $value;
        }

        return null;
    }

    // ── Locking and time ───────────────────────────────────────────────────

    /**
     * @param callable(): PromotionOutcome $callback
     */
    private function withPromotionLock(DocumentJob $job, string $clinicalContentFingerprint, callable $callback): PromotionOutcome
    {
        $lockName = sprintf('agentforge-fact:%d:%s', $job->patientId->value, $clinicalContentFingerprint);
        $rows = $this->executor->fetchRecords('SELECT GET_LOCK(?, 10) AS acquired', [$lockName]);
        if (!$this->lockAcquired($rows[0]['acquired'] ?? null)) {
            return PromotionOutcome::PromotionFailed;
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
