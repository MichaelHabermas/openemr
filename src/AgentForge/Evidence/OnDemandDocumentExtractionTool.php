<?php

/**
 * Read-only cited evidence from trusted AgentForge clinical document jobs.
 *
 * Re-runs document extraction at query time for jobs that succeeded but
 * whose facts have not yet been persisted to clinical_document_facts.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\AgentForge\Evidence;

use DomainException;
use OpenEMR\AgentForge\Auth\PatientId;
use OpenEMR\AgentForge\DatabaseExecutor;
use OpenEMR\AgentForge\Deadline;
use OpenEMR\AgentForge\Document\DocumentId;
use OpenEMR\AgentForge\Document\DocumentType;
use OpenEMR\AgentForge\Document\Extraction\DocumentExtractionProvider;
use OpenEMR\AgentForge\Document\Extraction\ExtractionProviderException;
use OpenEMR\AgentForge\Document\Worker\DocumentLoader;
use OpenEMR\AgentForge\Document\Worker\DocumentLoadException;
use OpenEMR\AgentForge\Evidence\DocumentEvidenceFormatting as Fmt;
use OpenEMR\AgentForge\Time\MonotonicClock;

final readonly class OnDemandDocumentExtractionTool implements ChartEvidenceTool
{
    public function __construct(
        private MonotonicClock $clock,
        private DatabaseExecutor $executor,
        private DocumentLoader $documentLoader,
        private DocumentExtractionProvider $extractionProvider,
        private int $limit = 6,
    ) {
    }

    public function section(): string
    {
        return ChartQuestionPlanner::SECTION_CLINICAL_DOCUMENTS;
    }

    public function collect(PatientId $patientId, ?Deadline $deadline = null): EvidenceResult
    {
        $items = [];
        $failures = [];
        foreach ($this->trustedDocumentRows($patientId) as $row) {
            if ($deadline?->exceeded()) {
                $failures[] = 'Some clinical documents could not be checked before the deadline.';
                break;
            }

            try {
                $documentId = new DocumentId(Fmt::positiveInt($row, 'document_id'));
                $docType = DocumentType::fromStringOrThrow(Fmt::string($row, 'doc_type'));
                $document = $this->documentLoader->load($documentId);
                $response = $this->extractionProvider->extract(
                    $documentId,
                    $document,
                    $docType,
                    $deadline ?? new Deadline($this->clock, -1),
                );
            } catch (DocumentLoadException | ExtractionProviderException | DomainException) {
                $failures[] = 'One trusted clinical document could not be read for answer evidence.';
                continue;
            }

            foreach ($response->facts as $fact) {
                $item = $this->itemFromFact($row, $fact);
                if ($item !== null) {
                    $items[] = $item;
                }
            }
        }

        if ($items !== []) {
            return new EvidenceResult($this->section(), $items, [], array_values(array_unique($failures)));
        }

        if ($failures !== []) {
            return new EvidenceResult($this->section(), [], [], array_values(array_unique($failures)));
        }

        return EvidenceResult::missing($this->section(), 'Trusted recent clinical document facts not found in the chart.');
    }

    /** @return list<array<string, mixed>> */
    private function trustedDocumentRows(PatientId $patientId): array
    {
        return $this->executor->fetchRecords(
            'SELECT j.id, j.patient_id, j.document_id, j.doc_type, j.finished_at, d.date AS document_date '
            . 'FROM clinical_document_processing_jobs j '
            . 'INNER JOIN clinical_document_identity_checks ic ON ic.job_id = j.id '
            . 'INNER JOIN documents d ON d.id = j.document_id '
            . 'WHERE j.patient_id = ? '
            . 'AND j.status = ? '
            . 'AND j.retracted_at IS NULL '
            . 'AND ic.patient_id = j.patient_id '
            . 'AND ic.document_id = j.document_id '
            . 'AND (ic.identity_status IN (?, ?) OR ic.review_decision = ?) '
            . 'AND (ic.review_required = 0 OR ic.review_decision = ?) '
            . 'AND (d.deleted IS NULL OR d.deleted = 0) '
            . 'ORDER BY COALESCE(j.finished_at, d.date) DESC '
            . 'LIMIT ' . max(1, min(20, $this->limit)),
            [
                $patientId->value,
                'succeeded',
                'identity_verified',
                'identity_review_approved',
                'approved',
                'approved',
            ],
        );
    }

    /**
     * @param array<string, mixed> $row
     * @param array<string, mixed> $fact
     */
    private function itemFromFact(array $row, array $fact): ?EvidenceItem
    {
        $citation = $this->stringKeyed($fact['citation'] ?? null);
        $fieldPath = Fmt::string($fact, 'field_path');
        $field = Fmt::string($citation, 'field_or_chunk_id') ?: $fieldPath;
        $page = Fmt::string($citation, 'page_or_section') ?: 'unknown page';
        $docType = Fmt::string($row, 'doc_type');
        $jobId = (string) Fmt::positiveInt($row, 'id');

        if (($fact['type'] ?? null) === 'lab_result') {
            $label = Fmt::string($fact, 'test_name') ?: Fmt::string($fact, 'label');
            $value = Fmt::string($fact, 'value');
            $unit = Fmt::string($fact, 'unit');
            $result = $unit !== '' && !str_contains($value, $unit)
                ? trim($value . ' ' . $unit)
                : $value;
            if ($label === '' || $result === '') {
                return null;
            }

            $parts = [$result];
            foreach (['reference_range' => 'reference range', 'abnormal_flag' => 'abnormal'] as $key => $name) {
                $value = Fmt::string($fact, $key);
                if ($value !== '') {
                    $parts[] = sprintf('%s: %s', $name, $value);
                }
            }
            $parts[] = Fmt::evidenceCitationSuffix($docType, $page, $field);

            return new EvidenceItem(
                'document',
                'clinical_document_processing_jobs',
                sprintf('%s:%s', $jobId, $field),
                Fmt::sourceDate(
                    Fmt::string($fact, 'collected_at'),
                    Fmt::string($row, 'finished_at'),
                    Fmt::string($row, 'document_date'),
                ),
                $label,
                EvidenceText::bounded(implode('; ', $parts), 300),
                $this->citationMetadata($row, $fact, $citation, $field, $page),
            );
        }

        if (($fact['type'] ?? null) === 'intake_finding') {
            $label = Fmt::string($fact, 'label');
            $value = Fmt::string($fact, 'value');
            if ($label === '' || $value === '') {
                return null;
            }

            return new EvidenceItem(
                'document',
                'clinical_document_processing_jobs',
                sprintf('%s:%s', $jobId, $field),
                Fmt::sourceDate(
                    Fmt::string($row, 'finished_at'),
                    Fmt::string($row, 'document_date'),
                ),
                str_replace('_', ' ', $label),
                EvidenceText::bounded(sprintf('%s; %s', $value, Fmt::evidenceCitationSuffix($docType, $page, $field)), 300),
                $this->citationMetadata($row, $fact, $citation, $field, $page),
            );
        }

        return null;
    }

    /**
     * @param array<string, mixed> $row
     * @param array<string, mixed> $fact
     * @param array<string, mixed> $citation
     * @return array<string, mixed>
     */
    private function citationMetadata(array $row, array $fact, array $citation, string $field, string $page): array
    {
        $metadata = [
            'source_type' => Fmt::string($citation, 'source_type') ?: Fmt::string($row, 'doc_type'),
            'source_id' => Fmt::string($citation, 'source_id') ?: 'document:' . Fmt::string($row, 'document_id'),
            'document_id' => Fmt::positiveInt($row, 'document_id'),
            'job_id' => Fmt::positiveInt($row, 'id'),
            'page_or_section' => $page,
            'field_or_chunk_id' => $field,
            'quote_or_value' => EvidenceText::bounded(Fmt::string($citation, 'quote_or_value'), 240),
        ];

        $box = Fmt::normalizedBoundingBox($citation['bounding_box'] ?? $fact['bounding_box'] ?? null);
        if ($box !== null) {
            $metadata['bounding_box'] = $box;
        }

        return array_filter(
            $metadata,
            static fn (mixed $value): bool => $value !== '',
        );
    }

    /** @return array<string, mixed> */
    private function stringKeyed(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        $out = [];
        foreach ($value as $key => $item) {
            if (is_string($key)) {
                $out[$key] = $item;
            }
        }

        return $out;
    }
}
