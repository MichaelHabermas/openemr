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
        private string $documentUrlBase = 'agent_document_source.php',
        private string $pageImageUrlBase = 'agent_document_source_page.php',
        private DocumentCitationNormalizer $citationNormalizer = new DocumentCitationNormalizer(),
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

        $normalized = $this->citationNormalizer->normalize($citation, $structured);

        return new DocumentCitationReview(
            $documentId->value,
            $jobId->value,
            $this->positiveIntOrNull($row['id'] ?? null),
            $this->documentUrl($patientId, $documentId, $jobId, $factId),
            $this->pageImageUrl($patientId, $documentId, $jobId, $factId, $normalized->pageNumber),
            $normalized->pageOrSection,
            $normalized->pageNumber,
            $normalized->fieldOrChunkId,
            $normalized->quoteOrValue,
            $normalized->boundingBox,
        );
    }

    private function documentUrl(PatientId $patientId, DocumentId $documentId, DocumentJobId $jobId, ?int $factId): string
    {
        $separator = str_contains($this->documentUrlBase, '?') ? '&' : '?';
        $url = $this->documentUrlBase
            . $separator . 'patient_id=' . rawurlencode((string) $patientId->value)
            . '&document_id=' . rawurlencode((string) $documentId->value)
            . '&job_id=' . rawurlencode((string) $jobId->value)
            . '&as_file=false';
        if ($factId !== null) {
            $url .= '&fact_id=' . rawurlencode((string) $factId);
        }

        return $url;
    }

    private function pageImageUrl(
        PatientId $patientId,
        DocumentId $documentId,
        DocumentJobId $jobId,
        ?int $factId,
        ?int $pageNumber,
    ): string {
        $separator = str_contains($this->pageImageUrlBase, '?') ? '&' : '?';
        $url = $this->pageImageUrlBase
            . $separator . 'patient_id=' . rawurlencode((string) $patientId->value)
            . '&document_id=' . rawurlencode((string) $documentId->value)
            . '&job_id=' . rawurlencode((string) $jobId->value)
            . '&page=' . rawurlencode((string) max(1, $pageNumber ?? 1));
        if ($factId !== null) {
            $url .= '&fact_id=' . rawurlencode((string) $factId);
        }

        return $url;
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
