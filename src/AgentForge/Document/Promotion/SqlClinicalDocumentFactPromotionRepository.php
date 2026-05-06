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
use OpenEMR\AgentForge\DefaultDatabaseExecutor;
use OpenEMR\AgentForge\Document\DocumentJob;
use OpenEMR\AgentForge\Document\Schema\BoundingBox;
use OpenEMR\AgentForge\Document\Schema\Certainty;
use OpenEMR\AgentForge\Document\Schema\CertaintyClassifier;
use OpenEMR\AgentForge\Document\Schema\DocumentCitation;
use OpenEMR\AgentForge\Document\Schema\IntakeFormExtraction;
use OpenEMR\AgentForge\Document\Schema\IntakeFormFinding;
use OpenEMR\AgentForge\Document\Schema\LabPdfExtraction;
use OpenEMR\AgentForge\Document\Schema\LabResultRow;

final readonly class SqlClinicalDocumentFactPromotionRepository implements ClinicalDocumentFactPromotionRepository
{
    private CertaintyClassifier $certaintyClassifier;

    public function __construct(private ?DatabaseExecutor $executor = null)
    {
        $this->certaintyClassifier = new CertaintyClassifier();
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
                    'promoted' => ++$promoted,
                    'needs_review' => ++$needsReview,
                    default => ++$skipped,
                };
            }
        } else {
            foreach ($extraction->findings as $index => $finding) {
                $status = $this->promoteIntakeFinding($job, $finding, sprintf('findings[%d]', $index));
                match ($status) {
                    'promoted' => ++$promoted,
                    'needs_review' => ++$needsReview,
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

        $rows = $this->db()->fetchRecords(
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
            return 'skipped_missing_job_id';
        }

        if (
            $this->certaintyClassifier->classify($job->docType, $row) !== Certainty::Verified
            || $row->testName === ''
            || $row->value === ''
        ) {
            return $this->upsertLedger($job, 'lab_result', $fieldPath, $row->testName, $this->labValueJson($row), $row->citation, 'needs_review');
        }

        $factHash = $this->factHash('lab_result', $row->testName, $this->stableLabValueJson($row));
        return $this->withPromotionLock($job, $factHash, function () use ($job, $row, $fieldPath, $factHash): string {
            $jobId = $job->id->value;

            $existing = $this->existingPromotedFact($job, $factHash);
            if ($existing !== []) {
                if ($this->intValue($existing, 'job_id') === $jobId) {
                    return 'skipped_duplicate';
                }

                return $this->upsertLedger(
                    $job,
                    'lab_result',
                    $fieldPath,
                    $row->testName,
                    $this->labValueJson($row),
                    $row->citation,
                    'skipped_duplicate',
                    $this->nullableString($existing, 'native_table'),
                    $this->nullableString($existing, 'native_id'),
                    $factHash,
                );
            }

            $now = $this->now();
            $collectedAt = $this->dateTimeOrNull($row->collectedAt) ?? $now;
            $orderId = $this->db()->insert(
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
            $this->db()->executeStatement(
                'INSERT INTO procedure_order_code '
                . '(procedure_order_id, procedure_order_seq, procedure_code, procedure_name, procedure_source, procedure_order_title, procedure_type) '
                . 'VALUES (?, 1, ?, ?, ?, ?, ?)',
                [
                    $orderId,
                    substr('agentforge-' . $factHash, 0, 31),
                    $row->testName,
                    '1',
                    $row->testName,
                    'laboratory_test',
                ],
            );
            $reportId = $this->db()->insert(
                'INSERT INTO procedure_report '
                . '(procedure_order_id, procedure_order_seq, date_collected, date_report, source, specimen_num, report_status, review_status, report_notes) '
                . 'VALUES (?, 1, ?, ?, 0, ?, ?, ?, ?)',
                [
                    $orderId,
                    $collectedAt,
                    $now,
                    sprintf('agentforge-job-%d', $jobId),
                    'complete',
                    'reviewed',
                    'AgentForge promoted from verified clinical document.',
                ],
            );
            $resultId = $this->db()->insert(
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
                    sprintf('agentforge-fact:%s', $factHash),
                    $job->documentId->value,
                    'final',
                ],
            );

            return $this->upsertLedger(
                $job,
                'lab_result',
                $fieldPath,
                $row->testName,
                $this->labValueJson($row),
                $row->citation,
                'promoted',
                'procedure_result',
                (string) $resultId,
                $factHash,
            );
        });
    }

    private function promoteIntakeFinding(DocumentJob $job, IntakeFormFinding $finding, string $fieldPath): string
    {
        if ($job->id === null) {
            return 'skipped_missing_job_id';
        }

        $nativeType = $this->nativeListType($finding);
        if ($this->certaintyClassifier->classify($job->docType, $finding) !== Certainty::Verified || $nativeType === null) {
            return $this->upsertLedger($job, 'intake_finding', $fieldPath, $finding->field, $this->findingValueJson($finding), $finding->citation, 'needs_review');
        }

        $factHash = $this->factHash('intake_finding', $finding->field, $this->stableFindingValueJson($finding));
        return $this->withPromotionLock($job, $factHash, function () use ($job, $finding, $fieldPath, $nativeType, $factHash): string {
            $existing = $this->existingPromotedFact($job, $factHash);
            if ($existing !== []) {
                if ($this->intValue($existing, 'job_id') === $job->id->value) {
                    return 'skipped_duplicate';
                }

                return $this->upsertLedger(
                    $job,
                    'intake_finding',
                    $fieldPath,
                    $finding->field,
                    $this->findingValueJson($finding),
                    $finding->citation,
                    'skipped_duplicate',
                    $this->nullableString($existing, 'native_table'),
                    $this->nullableString($existing, 'native_id'),
                    $factHash,
                );
            }

            $listId = $this->db()->insert(
                'INSERT INTO lists '
                . '(date, type, title, begdate, activity, comments, pid, user, groupname, external_id) '
                . 'VALUES (?, ?, ?, ?, 1, ?, ?, ?, ?, ?)',
                [
                    $this->now(),
                    $nativeType,
                    $finding->value,
                    $this->now(),
                    sprintf('AgentForge promoted from document %d; fact:%s', $job->documentId->value, $factHash),
                    $job->patientId->value,
                    'AgentForge',
                    'AgentForge',
                    substr('agentforge-' . $factHash, 0, 20),
                ],
            );

            return $this->upsertLedger(
                $job,
                'intake_finding',
                $fieldPath,
                $finding->field,
                $this->findingValueJson($finding),
                $finding->citation,
                'promoted',
                'lists',
                (string) $listId,
                $factHash,
            );
        });
    }

    /**
     * @return array<string, mixed>
     */
    private function existingPromotedFact(DocumentJob $job, string $factHash): array
    {
        $rows = $this->db()->fetchRecords(
            'SELECT job_id, promotion_status, native_table, native_id '
            . 'FROM clinical_document_promoted_facts '
            . 'WHERE patient_id = ? AND fact_hash = ? AND promotion_status = ? '
            . 'ORDER BY id ASC LIMIT 1',
            [$job->patientId->value, $factHash, 'promoted'],
        );

        return $rows[0] ?? [];
    }

    /**
     * @param callable(): string $callback
     */
    private function withPromotionLock(DocumentJob $job, string $factHash, callable $callback): string
    {
        $lockName = sprintf('agentforge-fact:%d:%s', $job->patientId->value, $factHash);
        $rows = $this->db()->fetchRecords('SELECT GET_LOCK(?, 10) AS acquired', [$lockName]);
        if (!$this->lockAcquired($rows[0]['acquired'] ?? null)) {
            return 'skipped_lock_timeout';
        }

        try {
            return $callback();
        } finally {
            $this->db()->fetchRecords('SELECT RELEASE_LOCK(?) AS released', [$lockName]);
        }
    }

    private function lockAcquired(mixed $value): bool
    {
        return $value === 1 || $value === '1';
    }

    /**
     * @param array<string, mixed> $valueJson
     */
    private function upsertLedger(
        DocumentJob $job,
        string $factType,
        string $fieldPath,
        string $label,
        array $valueJson,
        DocumentCitation $citation,
        string $status,
        ?string $nativeTable = null,
        ?string $nativeId = null,
        ?string $knownHash = null,
    ): string {
        $factHash = $knownHash ?? $this->factHash($factType, $label, $valueJson);
        $this->db()->executeStatement(
            'INSERT INTO clinical_document_promoted_facts '
            . '(job_id, patient_id, document_id, doc_type, fact_type, field_path, display_label, value_json, citation_json, bounding_box_json, fact_hash, promotion_status, native_table, native_id, review_status, created_at, updated_at) '
            . 'VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?) '
            . 'ON DUPLICATE KEY UPDATE native_table = VALUES(native_table), native_id = VALUES(native_id), review_status = VALUES(review_status), updated_at = VALUES(updated_at)',
            [
                $job->id?->value,
                $job->patientId->value,
                $job->documentId->value,
                $job->docType->value,
                $factType,
                $fieldPath,
                $label,
                $this->json($valueJson),
                $this->json($this->citationJson($citation)),
                $citation->boundingBox === null ? null : $this->json($this->boundingBoxJson($citation->boundingBox)),
                $factHash,
                $status,
                $nativeTable,
                $nativeId,
                $status === 'needs_review' ? 'needs_review' : 'auto_accepted',
                $this->now(),
                $this->now(),
            ],
        );

        return $status;
    }

    private function nativeListType(IntakeFormFinding $finding): ?string
    {
        $field = strtolower($finding->field . ' ' . $finding->value);
        if (str_contains($field, 'allerg')) {
            return 'allergy';
        }
        if (str_contains($field, 'medication') || str_contains($field, 'medicine') || str_contains($field, 'current meds')) {
            return 'medication';
        }
        if (str_contains($field, 'family')) {
            return 'family_problem';
        }
        if (str_contains($field, 'problem') || str_contains($field, 'condition') || str_contains($field, 'concern') || str_contains($field, 'chief')) {
            return 'medical_problem';
        }

        return null;
    }

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

    /** @param array<string, mixed> $valueJson */
    private function factHash(string $factType, string $label, array $valueJson): string
    {
        return hash('sha256', $this->json([
            'fact_type' => $factType,
            'label' => $label,
            'value' => $valueJson,
        ]));
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
    private function json(array $data): string
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

    private function now(): string
    {
        return (new DateTimeImmutable())->format('Y-m-d H:i:s');
    }

    private function db(): DatabaseExecutor
    {
        return $this->executor ?? new DefaultDatabaseExecutor();
    }
}
