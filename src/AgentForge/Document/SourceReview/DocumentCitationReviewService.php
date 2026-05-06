<?php

/**
 * Resolves persisted AgentForge document facts into UI source-review payloads.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\AgentForge\Document\SourceReview;

use JsonException;
use OpenEMR\AgentForge\Auth\PatientId;
use OpenEMR\AgentForge\DatabaseExecutor;
use OpenEMR\AgentForge\Document\DocumentId;
use OpenEMR\AgentForge\Document\DocumentJobId;
use OpenEMR\AgentForge\StringKeyedArray;

final readonly class DocumentCitationReviewService
{
    public function __construct(
        private DatabaseExecutor $executor,
        private SourceDocumentAccessGate $accessGate,
        private string $documentUrlBase = '../../controller.php?document&retrieve',
    ) {
    }

    public function review(
        PatientId $patientId,
        DocumentId $documentId,
        DocumentJobId $jobId,
        ?int $factId = null,
    ): ?DocumentCitationReview {
        if (!$this->accessGate->allows($patientId, $documentId, $jobId, $factId)) {
            return null;
        }

        $rows = $this->executor->fetchRecords(
            'SELECT f.id, f.citation_json, f.structured_value_json '
            . 'FROM clinical_document_facts f '
            . 'WHERE f.patient_id = ? '
            . 'AND f.document_id = ? '
            . 'AND f.job_id = ? '
            . 'AND f.active = 1 '
            . 'AND f.retracted_at IS NULL '
            . 'AND f.deactivated_at IS NULL '
            . ($factId === null ? '' : 'AND f.id = ? ')
            . 'ORDER BY f.id DESC LIMIT 1',
            $factId === null
                ? [$patientId->value, $documentId->value, $jobId->value]
                : [$patientId->value, $documentId->value, $jobId->value, $factId],
        );

        if ($rows === []) {
            return null;
        }

        $row = $rows[0];
        try {
            $citation = $this->jsonObject($this->string($row, 'citation_json'));
            $structured = $this->jsonObject($this->string($row, 'structured_value_json'));
        } catch (JsonException) {
            return null;
        }

        $pageOrSection = $this->string($citation, 'page_or_section') ?: 'unknown page';
        $field = $this->string($citation, 'field_or_chunk_id') ?: $this->string($structured, 'field_path');

        return new DocumentCitationReview(
            $documentId->value,
            $jobId->value,
            $this->positiveIntOrNull($row['id'] ?? null),
            $this->documentUrl($patientId, $documentId),
            $pageOrSection,
            $this->pageNumber($pageOrSection),
            $field === '' ? 'unknown field' : $field,
            $this->string($citation, 'quote_or_value'),
            $this->normalizedBoundingBox($citation['bounding_box'] ?? $structured['bounding_box'] ?? null),
        );
    }

    private function documentUrl(PatientId $patientId, DocumentId $documentId): string
    {
        return $this->documentUrlBase
            . '&patient_id=' . rawurlencode((string) $patientId->value)
            . '&document_id=' . rawurlencode((string) $documentId->value)
            . '&as_file=false';
    }

    private function pageNumber(string $pageOrSection): ?int
    {
        if (preg_match('/\bpage\s*(\d+)\b/i', $pageOrSection, $matches) === 1) {
            return max(1, (int) $matches[1]);
        }

        if (preg_match('/^\s*(\d+)\s*$/', $pageOrSection, $matches) === 1) {
            return max(1, (int) $matches[1]);
        }

        return null;
    }

    /** @return array{x: float, y: float, width: float, height: float}|null */
    private function normalizedBoundingBox(mixed $value): ?array
    {
        if (!is_array($value)) {
            return null;
        }

        $box = [];
        foreach (['x', 'y', 'width', 'height'] as $key) {
            if (!isset($value[$key]) || !is_numeric($value[$key])) {
                return null;
            }
            $number = (float) $value[$key];
            if ($number < 0.0 || $number > 1.0) {
                return null;
            }
            $box[$key] = $number;
        }

        if ($box['width'] <= 0.0 || $box['height'] <= 0.0) {
            return null;
        }

        return [
            'x' => $box['x'],
            'y' => $box['y'],
            'width' => $box['width'],
            'height' => $box['height'],
        ];
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

        return is_array($decoded) ? StringKeyedArray::filter($decoded) : [];
    }

    /** @param array<string, mixed> $row */
    private function string(array $row, string $key): string
    {
        $value = $row[$key] ?? '';

        return is_scalar($value) ? trim((string) $value) : '';
    }

    private function positiveIntOrNull(mixed $value): ?int
    {
        if (is_int($value) && $value > 0) {
            return $value;
        }
        if (is_string($value) && ctype_digit($value) && (int) $value > 0) {
            return (int) $value;
        }

        return null;
    }
}
