<?php

/**
 * SQL-backed document fact repository.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\AgentForge\Document;

use DateTimeImmutable;
use OpenEMR\AgentForge\Auth\PatientId;
use OpenEMR\AgentForge\DatabaseExecutor;
use OpenEMR\AgentForge\Deadline;
use OpenEMR\AgentForge\StringKeyedArray;

final readonly class SqlDocumentFactRepository implements DocumentFactRepository
{
    public function __construct(private DatabaseExecutor $executor)
    {
    }

    public function upsert(DocumentFact $fact): int
    {
        $this->executor->executeStatement(
            'INSERT INTO clinical_document_facts '
            . '(patient_id, document_id, job_id, identity_check_id, doc_type, fact_type, certainty, '
            . 'fact_fingerprint, clinical_content_fingerprint, fact_text, structured_value_json, citation_json, '
            . 'confidence, promotion_status, active, created_at, retracted_at, retraction_reason, deactivated_at) '
            . 'VALUES (?, ?, ?, (SELECT id FROM clinical_document_identity_checks WHERE job_id = ? LIMIT 1), ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, COALESCE(?, NOW()), ?, ?, ?) '
            . 'ON DUPLICATE KEY UPDATE job_id = VALUES(job_id), identity_check_id = VALUES(identity_check_id), '
            . 'certainty = VALUES(certainty), clinical_content_fingerprint = VALUES(clinical_content_fingerprint), '
            . 'fact_text = VALUES(fact_text), structured_value_json = VALUES(structured_value_json), '
            . 'citation_json = VALUES(citation_json), confidence = VALUES(confidence), '
            . 'promotion_status = VALUES(promotion_status), active = VALUES(active), '
            . 'retracted_at = VALUES(retracted_at), retraction_reason = VALUES(retraction_reason), '
            . 'deactivated_at = VALUES(deactivated_at)',
            [
                $fact->patientId->value,
                $fact->documentId->value,
                $fact->jobId->value,
                $fact->jobId->value,
                $fact->docType->value,
                $fact->factType,
                $fact->certainty,
                $fact->factFingerprint,
                $fact->clinicalContentFingerprint,
                $fact->factText,
                $this->json($fact->structuredValue),
                $this->json($fact->citation),
                $fact->confidence,
                $fact->promotionStatus,
                $fact->active ? 1 : 0,
                $fact->createdAt?->format('Y-m-d H:i:s'),
                $fact->retractedAt?->format('Y-m-d H:i:s'),
                $fact->retractionReason,
                $fact->deactivatedAt?->format('Y-m-d H:i:s'),
            ],
        );

        $rows = $this->executor->fetchRecords(
            'SELECT id FROM clinical_document_facts '
            . 'WHERE patient_id = ? AND document_id = ? AND doc_type = ? AND fact_fingerprint = ? LIMIT 1',
            [$fact->patientId->value, $fact->documentId->value, $fact->docType->value, $fact->factFingerprint],
        );

        return $this->intValue($rows[0] ?? [], 'id');
    }

    public function findRecentForPatient(PatientId $patientId, int $limit, ?Deadline $deadline = null): array
    {
        $rows = $this->executor->fetchRecords(
            'SELECT f.* '
            . 'FROM clinical_document_facts f '
            . 'INNER JOIN clinical_document_processing_jobs j ON j.id = f.job_id '
            . 'INNER JOIN clinical_document_identity_checks ic ON ic.job_id = f.job_id '
            . 'INNER JOIN documents d ON d.id = f.document_id '
            . 'WHERE f.patient_id = ? '
            . 'AND f.active = 1 '
            . 'AND f.retracted_at IS NULL '
            . 'AND f.deactivated_at IS NULL '
            . 'AND f.certainty IN (?, ?) '
            . 'AND j.status = ? '
            . 'AND j.retracted_at IS NULL '
            . 'AND (ic.identity_status IN (?, ?) OR ic.review_decision = ?) '
            . 'AND (ic.review_required = 0 OR ic.review_decision = ?) '
            . 'AND d.activity = 1 '
            . 'AND (d.deleted IS NULL OR d.deleted = 0) '
            . 'ORDER BY f.created_at DESC LIMIT ' . $this->limit($limit),
            [
                $patientId->value,
                'verified',
                'document_fact',
                'succeeded',
                'identity_verified',
                'identity_review_approved',
                'approved',
                'approved',
            ],
            $deadline,
        );

        return array_values(array_filter(array_map($this->hydrate(...), $rows)));
    }

    /** @param array<string, mixed> $row */
    private function hydrate(array $row): ?DocumentFact
    {
        $docType = DocumentType::tryFrom($this->stringValue($row, 'doc_type'));
        if ($docType === null) {
            return null;
        }

        return new DocumentFact(
            $this->intValue($row, 'id'),
            new PatientId($this->intValue($row, 'patient_id')),
            new DocumentId($this->intValue($row, 'document_id')),
            new DocumentJobId($this->intValue($row, 'job_id')),
            $docType,
            $this->stringValue($row, 'fact_type'),
            $this->stringValue($row, 'certainty'),
            $this->stringValue($row, 'fact_fingerprint'),
            $this->stringValue($row, 'clinical_content_fingerprint'),
            $this->stringValue($row, 'fact_text'),
            $this->decode($this->stringValue($row, 'structured_value_json')),
            $this->decode($this->stringValue($row, 'citation_json')),
            $this->floatOrNull($row, 'confidence'),
            $this->stringValue($row, 'promotion_status'),
            $this->intValue($row, 'active') === 1,
            $this->dateOrNull($row['created_at'] ?? null),
            $this->dateOrNull($row['retracted_at'] ?? null),
            $this->nullableString($row, 'retraction_reason'),
            $this->dateOrNull($row['deactivated_at'] ?? null),
        );
    }

    /** @param array<string, mixed> $value */
    private function json(array $value): string
    {
        return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    }

    /** @return array<string, mixed> */
    private function decode(string $value): array
    {
        $decoded = json_decode($value, true);

        return is_array($decoded) ? StringKeyedArray::filter($decoded) : [];
    }

    /** @param array<string, mixed> $row */
    private function intValue(array $row, string $key): int
    {
        $value = $row[$key] ?? 0;
        if (is_int($value)) {
            return $value;
        }
        if (is_string($value) && ctype_digit($value)) {
            return (int) $value;
        }

        return 0;
    }

    /** @param array<string, mixed> $row */
    private function stringValue(array $row, string $key): string
    {
        $value = $row[$key] ?? '';

        return is_scalar($value) ? (string) $value : '';
    }

    /** @param array<string, mixed> $row */
    private function nullableString(array $row, string $key): ?string
    {
        $value = $row[$key] ?? null;

        return is_scalar($value) ? (string) $value : null;
    }

    /** @param array<string, mixed> $row */
    private function floatOrNull(array $row, string $key): ?float
    {
        $value = $row[$key] ?? null;
        if (is_int($value) || is_float($value)) {
            return (float) $value;
        }
        if (is_string($value) && is_numeric($value)) {
            return (float) $value;
        }

        return null;
    }

    private function dateOrNull(mixed $value): ?DateTimeImmutable
    {
        if (!is_string($value) || trim($value) === '') {
            return null;
        }

        $date = new DateTimeImmutable($value);

        return $date;
    }

    private function limit(int $limit): int
    {
        return max(1, min(20, $limit));
    }
}
