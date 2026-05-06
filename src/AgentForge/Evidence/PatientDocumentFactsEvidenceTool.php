<?php

/**
 * Read-only cited evidence from persisted AgentForge patient document facts.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\AgentForge\Evidence;

use DomainException;
use JsonException;
use OpenEMR\AgentForge\Auth\PatientId;
use OpenEMR\AgentForge\DatabaseExecutor;
use OpenEMR\AgentForge\Deadline;

final readonly class PatientDocumentFactsEvidenceTool implements ChartEvidenceTool
{
    public function __construct(
        private DatabaseExecutor $executor,
        private int $limit = 12,
    ) {
    }

    public function section(): string
    {
        return ChartQuestionPlanner::SECTION_CLINICAL_DOCUMENTS;
    }

    public function collect(PatientId $patientId, ?Deadline $deadline = null): EvidenceResult
    {
        if ($deadline?->exceeded()) {
            return EvidenceResult::failure($this->section(), 'Patient document facts could not be checked before the deadline.');
        }

        $items = [];
        foreach ($this->activeFactRows($patientId, $deadline) as $row) {
            try {
                $item = $this->itemFromRow($row);
            } catch (DomainException | JsonException) {
                continue;
            }

            if ($item !== null) {
                $items[] = $item;
            }
        }

        if ($items === []) {
            return EvidenceResult::missing($this->section(), 'Trusted active patient document facts not found in the chart.');
        }

        return new EvidenceResult($this->section(), $items);
    }

    /** @return list<array<string, mixed>> */
    private function activeFactRows(PatientId $patientId, ?Deadline $deadline): array
    {
        return $this->executor->fetchRecords(
            'SELECT f.id, f.patient_id, f.document_id, f.job_id, f.identity_check_id, f.doc_type, '
            . 'f.fact_type, f.certainty, f.fact_fingerprint, f.clinical_content_fingerprint, '
            . 'f.fact_text, f.structured_value_json, f.citation_json, f.confidence, '
            . 'f.promotion_status, f.created_at, j.finished_at, d.date AS document_date '
            . 'FROM clinical_document_facts f '
            . 'INNER JOIN clinical_document_processing_jobs j ON j.id = f.job_id '
            . 'INNER JOIN clinical_document_identity_checks ic ON ic.id = f.identity_check_id '
            . 'INNER JOIN documents d ON d.id = f.document_id '
            . 'WHERE f.patient_id = ? '
            . 'AND f.active = 1 '
            . 'AND f.retracted_at IS NULL '
            . 'AND f.deactivated_at IS NULL '
            . 'AND f.certainty IN (?, ?) '
            . 'AND j.patient_id = f.patient_id '
            . 'AND j.document_id = f.document_id '
            . 'AND j.status = ? '
            . 'AND j.retracted_at IS NULL '
            . 'AND ic.patient_id = f.patient_id '
            . 'AND ic.document_id = f.document_id '
            . 'AND ic.job_id = f.job_id '
            . 'AND (ic.identity_status IN (?, ?) OR ic.review_decision = ?) '
            . 'AND (ic.review_required = 0 OR ic.review_decision = ?) '
            . 'AND d.activity = 1 '
            . 'AND (d.deleted IS NULL OR d.deleted = 0) '
            . 'ORDER BY COALESCE(j.finished_at, f.created_at, d.date) DESC, f.id DESC '
            . 'LIMIT ' . max(1, min(50, $this->limit)),
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
    }

    /** @param array<string, mixed> $row */
    private function itemFromRow(array $row): ?EvidenceItem
    {
        $factText = $this->string($row, 'fact_text');
        if ($factText === '') {
            return null;
        }

        $citation = $this->jsonObject($this->string($row, 'citation_json'));
        $structured = $this->jsonObject($this->string($row, 'structured_value_json'));
        $field = $this->string($citation, 'field_or_chunk_id') ?: $this->string($structured, 'field_path');
        if ($field === '') {
            $field = 'fact:' . (string) $this->positiveInt($row, 'id');
        }
        $page = $this->string($citation, 'page_or_section') ?: 'unknown page';
        $docType = $this->string($row, 'doc_type');
        $label = $this->displayLabel($row, $structured);

        return new EvidenceItem(
            'document',
            'clinical_document_facts',
            (string) $this->positiveInt($row, 'id'),
            $this->sourceDate($row, $this->string($structured, 'collected_at')),
            $label,
            EvidenceText::bounded(sprintf('%s; %s', $factText, $this->evidenceCitationSuffix($docType, $page, $field)), 300),
            $this->citationMetadata($row, $citation, $structured, $field, $page),
        );
    }

    /**
     * @param array<string, mixed> $row
     * @param array<string, mixed> $structured
     */
    private function displayLabel(array $row, array $structured): string
    {
        foreach (['display_label', 'test_name', 'label', 'name'] as $key) {
            $value = $this->string($structured, $key);
            if ($value !== '') {
                return str_replace('_', ' ', $value);
            }
        }

        $factType = $this->string($row, 'fact_type');
        return $factType !== '' ? str_replace('_', ' ', $factType) : 'Patient document fact';
    }

    /**
     * @param array<string, mixed> $row
     * @param array<string, mixed> $citation
     * @param array<string, mixed> $structured
     * @return array<string, mixed>
     */
    private function citationMetadata(array $row, array $citation, array $structured, string $field, string $page): array
    {
        $metadata = [
            'source_type' => $this->string($citation, 'source_type') ?: $this->string($row, 'doc_type'),
            'source_id' => $this->string($citation, 'source_id') ?: 'document:' . $this->string($row, 'document_id'),
            'document_id' => $this->positiveInt($row, 'document_id'),
            'job_id' => $this->positiveInt($row, 'job_id'),
            'identity_check_id' => $this->positiveInt($row, 'identity_check_id'),
            'fact_id' => $this->positiveInt($row, 'id'),
            'fact_type' => $this->string($row, 'fact_type'),
            'certainty' => $this->string($row, 'certainty'),
            'promotion_status' => $this->string($row, 'promotion_status'),
            'fact_fingerprint' => $this->string($row, 'fact_fingerprint'),
            'clinical_content_fingerprint' => $this->string($row, 'clinical_content_fingerprint'),
            'page_or_section' => $page,
            'field_or_chunk_id' => $field,
            'quote_or_value' => EvidenceText::bounded($this->string($citation, 'quote_or_value'), 240),
        ];

        $box = $this->normalizedBoundingBox($citation['bounding_box'] ?? $structured['bounding_box'] ?? null);
        if ($box !== null) {
            $metadata['bounding_box'] = $box;
        }

        return array_filter(
            $metadata,
            static fn (mixed $value): bool => $value !== '',
        );
    }

    private function evidenceCitationSuffix(string $docType, string $page, string $field): string
    {
        return sprintf('Citation: %s, %s, %s', $docType, $page, $field);
    }

    /** @return array{x: float, y: float, width: float, height: float}|null */
    private function normalizedBoundingBox(mixed $value): ?array
    {
        if (!is_array($value)) {
            return null;
        }

        $numbers = [];
        foreach (['x', 'y', 'width', 'height'] as $key) {
            if (!isset($value[$key]) || !is_numeric($value[$key])) {
                return null;
            }
            $number = (float) $value[$key];
            if ($number < 0.0 || $number > 1.0) {
                return null;
            }
            $numbers[$key] = $number;
        }

        if ($numbers['width'] <= 0.0 || $numbers['height'] <= 0.0) {
            return null;
        }

        return [
            'x' => $numbers['x'],
            'y' => $numbers['y'],
            'width' => $numbers['width'],
            'height' => $numbers['height'],
        ];
    }

    /** @param array<string, mixed> $row */
    private function sourceDate(array $row, string $preferred = ''): string
    {
        foreach ([$preferred, $this->string($row, 'finished_at'), $this->string($row, 'created_at'), $this->string($row, 'document_date')] as $candidate) {
            if (preg_match('/^\d{4}-\d{2}-\d{2}/', $candidate, $matches) === 1) {
                return $matches[0];
            }
        }

        return 'unknown';
    }

    /** @param array<string, mixed> $row */
    private function string(array $row, string $key): string
    {
        $value = $row[$key] ?? '';
        return is_scalar($value) ? trim((string) $value) : '';
    }

    /** @param array<string, mixed> $row */
    private function positiveInt(array $row, string $key): int
    {
        $value = $row[$key] ?? null;
        if (is_int($value) && $value > 0) {
            return $value;
        }
        if (is_string($value) && ctype_digit($value) && (int) $value > 0) {
            return (int) $value;
        }

        throw new DomainException(sprintf('Patient document fact evidence row %s must be a positive integer.', $key));
    }

    /**
     * @return array<string, mixed>
     * @throws JsonException
     */
    private function jsonObject(string $json): array
    {
        if ($json === '') {
            return [];
        }

        $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($decoded)) {
            return [];
        }

        $out = [];
        foreach ($decoded as $key => $value) {
            if (is_string($key)) {
                $out[$key] = $value;
            }
        }

        return $out;
    }
}
