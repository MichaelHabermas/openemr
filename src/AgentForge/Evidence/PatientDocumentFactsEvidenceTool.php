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
use OpenEMR\AgentForge\Document\JobStatus;
use OpenEMR\AgentForge\StringKeyedArray;
use OpenEMR\AgentForge\Document\SourceReview\DocumentCitationNormalizer;
use OpenEMR\AgentForge\Document\SourceReview\NormalizedDocumentCitation;
use OpenEMR\AgentForge\Document\SourceReview\SourceReviewPresenter;
use OpenEMR\AgentForge\Document\TrustedDocumentGate;
use OpenEMR\AgentForge\Evidence\DocumentEvidenceFormatting as Fmt;

final readonly class PatientDocumentFactsEvidenceTool implements ChartEvidenceTool
{
    public function __construct(
        private DatabaseExecutor $executor,
        private int $limit = 12,
        private DocumentCitationNormalizer $citationNormalizer = new DocumentCitationNormalizer(),
        private TrustedDocumentGate $trustedDocuments = new TrustedDocumentGate(),
        private SourceReviewPresenter $presenter = new SourceReviewPresenter(),
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
                $item = $this->itemFromRow($row, $patientId);
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
            . 'AND f.certainty IN (?, ?, ?) '
            . 'AND j.patient_id = f.patient_id '
            . 'AND j.document_id = f.document_id '
            . 'AND ic.job_id = f.job_id '
            . $this->trustedDocuments->where(statuses: [JobStatus::Succeeded])
            . 'ORDER BY COALESCE(j.finished_at, f.created_at, d.date) DESC, f.id DESC '
            . 'LIMIT ' . max(1, min(50, $this->limit)),
            [
                $patientId->value,
                'verified',
                'document_fact',
                'needs_review',
                ...$this->trustedDocuments->binds([JobStatus::Succeeded]),
            ],
            $deadline,
        );
    }

    /** @param array<string, mixed> $row */
    private function itemFromRow(array $row, PatientId $patientId): ?EvidenceItem
    {
        $factText = Fmt::string($row, 'fact_text');
        if ($factText === '') {
            return null;
        }

        $citation = $this->jsonObject(Fmt::string($row, 'citation_json'));
        $structured = $this->jsonObject(Fmt::string($row, 'structured_value_json'));
        $normalizedCitation = $this->citationNormalizer->normalize($citation, $structured);
        $field = $normalizedCitation->fieldOrChunkId === 'unknown field'
            ? 'fact:' . (string) Fmt::positiveInt($row, 'id')
            : $normalizedCitation->fieldOrChunkId;
        $page = $normalizedCitation->pageOrSection;
        $docType = Fmt::string($row, 'doc_type');
        $inlineMarker = $this->presenter->inlineMarker($docType, $page, $field);
        $label = $this->displayLabel($row, $structured);
        $isNeedsReview = Fmt::string($row, 'certainty') === 'needs_review';

        return new EvidenceItem(
            $isNeedsReview ? 'document_review' : 'document',
            'clinical_document_facts',
            (string) Fmt::positiveInt($row, 'id'),
            Fmt::sourceDate(
                Fmt::string($structured, 'collected_at'),
                Fmt::string($row, 'finished_at'),
                Fmt::string($row, 'created_at'),
                Fmt::string($row, 'document_date'),
            ),
            $isNeedsReview ? 'Needs review: ' . $label : $label,
            EvidenceText::bounded(sprintf('%s; %s', $factText, $inlineMarker), 300),
            $this->citationMetadata($row, $normalizedCitation, $field, $page, $patientId),
        );
    }

    /**
     * @param array<string, mixed> $row
     * @param array<string, mixed> $structured
     */
    private function displayLabel(array $row, array $structured): string
    {
        foreach (['display_label', 'test_name', 'label', 'field', 'name'] as $key) {
            $value = Fmt::string($structured, $key);
            if ($value !== '') {
                return str_replace('_', ' ', $value);
            }
        }

        $factType = Fmt::string($row, 'fact_type');
        return $factType !== '' ? str_replace('_', ' ', $factType) : 'Patient document fact';
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function citationMetadata(array $row, NormalizedDocumentCitation $citation, string $field, string $page, PatientId $patientId): array
    {
        $docType = Fmt::string($row, 'doc_type');
        $documentId = Fmt::positiveInt($row, 'document_id');
        $jobId = Fmt::positiveInt($row, 'job_id');
        $factId = Fmt::positiveInt($row, 'id');
        $locator = $this->presenter->locator($docType, $citation);

        $metadata = [
            'source_type' => 'document',
            'doc_type' => $docType,
            'source_id' => $citation->sourceId !== '' ? $citation->sourceId : 'document:' . (string) $documentId,
            'document_id' => $documentId,
            'job_id' => $jobId,
            'identity_check_id' => Fmt::positiveInt($row, 'identity_check_id'),
            'fact_id' => $factId,
            'fact_type' => Fmt::string($row, 'fact_type'),
            'certainty' => Fmt::string($row, 'certainty'),
            'promotion_status' => Fmt::string($row, 'promotion_status'),
            'fact_fingerprint' => Fmt::string($row, 'fact_fingerprint'),
            'clinical_content_fingerprint' => Fmt::string($row, 'clinical_content_fingerprint'),
            'page_or_section' => $page,
            'field_or_chunk_id' => $field,
            'quote_or_value' => EvidenceText::bounded($citation->quoteOrValue, 240),
            'review_url' => $this->presenter->reviewUrl($documentId, $jobId, $factId),
            'open_source_url' => $this->presenter->openSourceUrl($patientId->value, $documentId, $jobId),
            'inline_marker' => $this->presenter->inlineMarker($docType, $page, $field),
            'locator' => $locator->toArray(),
        ];

        if ($citation->boundingBox !== null) {
            $metadata['bounding_box'] = $citation->boundingBox;
        }

        return array_filter(
            $metadata,
            static fn (mixed $value): bool => $value !== '',
        );
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

        return StringKeyedArray::filter($decoded);
    }
}
