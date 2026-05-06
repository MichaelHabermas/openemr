<?php

/**
 * Upserts promotion ledger rows and checks for existing promoted facts.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\AgentForge\Document\Promotion;

use OpenEMR\AgentForge\DatabaseExecutor;
use OpenEMR\AgentForge\Document\DocumentJob;
use OpenEMR\AgentForge\Document\Schema\DocumentCitation;
use Psr\Clock\ClockInterface;

final readonly class PromotionLedgerWriter
{
    public function __construct(
        private DatabaseExecutor $executor,
        private PromotionValueSerializer $serializer,
        private ClinicalContentFingerprint $fingerprints,
        private ClockInterface $wallClock,
    ) {
    }

    /**
     * @param array<string, mixed> $valueJson
     */
    public function upsertLedger(
        DocumentJob $job,
        string $factType,
        string $fieldPath,
        string $label,
        array $valueJson,
        DocumentCitation $citation,
        PromotionOutcome $outcome,
        ?string $promotedTable = null,
        ?string $promotedRecordId = null,
        ?string $promotedPkJson = null,
        string $factFingerprint = '',
        string $clinicalContentFingerprint = '',
        ?float $confidence = null,
        ?string $conflictReason = null,
    ): string {
        $factFingerprint = $factFingerprint !== '' ? $factFingerprint : $this->fingerprints->sourceFactFingerprint($job, $factType, $fieldPath, $valueJson);
        $clinicalContentFingerprint = $clinicalContentFingerprint !== '' ? $clinicalContentFingerprint : $this->fingerprints->patientClinicalFingerprint($factType, $label, $valueJson);
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
                $job->patientId->value,
                $job->documentId->value,
                $job->id?->value,
                $factFingerprint,
                $job->docType->value,
                $factType,
                $fieldPath,
                $label,
                $this->serializer->json($valueJson),
                $factFingerprint,
                $clinicalContentFingerprint,
                $promotedTable ?? '',
                $promotedRecordId,
                $promotedPkJson,
                $outcome->value,
                $clinicalContentFingerprint,
                $conflictReason,
                $this->serializer->json($this->serializer->citationJson($citation)),
                $citation->boundingBox === null ? null : $this->serializer->json($this->serializer->boundingBoxJson($citation->boundingBox)),
                $confidence,
                $outcome->reviewStatus(),
                $now,
                $now,
            ],
        );

        return $outcome->value;
    }

    /**
     * @return array<string, mixed>
     */
    public function existingPromotedFact(DocumentJob $job, string $clinicalContentFingerprint, string $legacyFactHash): array
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
    public function statusForExistingFact(
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
    ): ?string {
        if ($existing === []) {
            return null;
        }
        if ($this->serializer->intValue($existing, 'job_id') === $jobId) {
            return PromotionOutcome::DuplicateSkipped->value;
        }

        return $this->upsertLedger(
            $job,
            $factType,
            $fieldPath,
            $label,
            $valueJson,
            $citation,
            PromotionOutcome::DuplicateSkipped,
            $this->serializer->nullableString($existing, 'promoted_table'),
            $this->serializer->nullableString($existing, 'promoted_record_id'),
            $this->serializer->nullableString($existing, 'promoted_pk_json'),
            $factFingerprint,
            $clinicalContentFingerprint,
            $confidence,
        );
    }

    private function now(): string
    {
        return $this->wallClock->now()->format('Y-m-d H:i:s');
    }
}
