<?php

/**
 * Read-only cited evidence from trusted AgentForge clinical document jobs.
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
use OpenEMR\AgentForge\Document\Extraction\FixtureExtractionProvider;
use OpenEMR\AgentForge\Document\Worker\DocumentLoader;
use OpenEMR\AgentForge\Document\Worker\DocumentLoadException;
use OpenEMR\AgentForge\Document\Worker\OpenEmrDocumentLoader;
use OpenEMR\AgentForge\Time\MonotonicClock;

final readonly class ClinicalDocumentEvidenceTool implements ChartEvidenceTool
{
    public function __construct(
        private MonotonicClock $clock,
        private DatabaseExecutor $executor,
        private ?DocumentLoader $documentLoader = null,
        private ?DocumentExtractionProvider $extractionProvider = null,
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
                $documentId = new DocumentId($this->positiveInt($row, 'document_id'));
                $docType = DocumentType::fromStringOrThrow($this->string($row, 'doc_type'));
                $document = $this->loader()->load($documentId);
                $response = $this->provider()->extract(
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
            . 'AND d.activity = 1 '
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
        $fieldPath = $this->string($fact, 'field_path');
        $field = $this->string($citation, 'field_or_chunk_id') ?: $fieldPath;
        $page = $this->string($citation, 'page_or_section') ?: 'unknown page';
        $docType = $this->string($row, 'doc_type');
        $jobId = (string) $this->positiveInt($row, 'id');

        if (($fact['type'] ?? null) === 'lab_result') {
            $label = $this->string($fact, 'test_name') ?: $this->string($fact, 'label');
            $value = $this->string($fact, 'value');
            $unit = $this->string($fact, 'unit');
            $result = $unit !== '' && !str_contains($value, $unit)
                ? trim($value . ' ' . $unit)
                : $value;
            if ($label === '' || $result === '') {
                return null;
            }

            $parts = [$result];
            foreach (['reference_range' => 'reference range', 'abnormal_flag' => 'abnormal'] as $key => $name) {
                $value = $this->string($fact, $key);
                if ($value !== '') {
                    $parts[] = sprintf('%s: %s', $name, $value);
                }
            }
            $parts[] = $this->evidenceCitationSuffix($docType, $page, $field);

            return new EvidenceItem(
                'document',
                'clinical_document_processing_jobs',
                sprintf('%s:%s', $jobId, $field),
                $this->sourceDate($row, $this->string($fact, 'collected_at')),
                $label,
                EvidenceText::bounded(implode('; ', $parts), 300),
                $this->citationMetadata($row, $fact, $citation, $field, $page),
            );
        }

        if (($fact['type'] ?? null) === 'intake_finding') {
            $label = $this->string($fact, 'label');
            $value = $this->string($fact, 'value');
            if ($label === '' || $value === '') {
                return null;
            }

            return new EvidenceItem(
                'document',
                'clinical_document_processing_jobs',
                sprintf('%s:%s', $jobId, $field),
                $this->sourceDate($row),
                str_replace('_', ' ', $label),
                EvidenceText::bounded(sprintf('%s; %s', $value, $this->evidenceCitationSuffix($docType, $page, $field)), 300),
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
            'source_type' => $this->string($citation, 'source_type') ?: $this->string($row, 'doc_type'),
            'source_id' => $this->string($citation, 'source_id') ?: $this->documentSourceId($row),
            'document_id' => $this->positiveInt($row, 'document_id'),
            'job_id' => $this->positiveInt($row, 'id'),
            'page_or_section' => $page,
            'field_or_chunk_id' => $field,
            'quote_or_value' => EvidenceText::bounded($this->string($citation, 'quote_or_value'), 240),
        ];

        $box = $this->normalizedBoundingBox($citation['bounding_box'] ?? $fact['bounding_box'] ?? null);
        if ($box !== null) {
            $metadata['bounding_box'] = $box;
        }

        return array_filter(
            $metadata,
            static fn (mixed $value): bool => $value !== '',
        );
    }

    /** @param array<string, mixed> $row */
    private function documentSourceId(array $row): string
    {
        return 'document:' . $this->string($row, 'document_id');
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
        foreach ([$preferred, $this->string($row, 'finished_at'), $this->string($row, 'document_date')] as $candidate) {
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

        throw new DomainException(sprintf('Clinical document evidence row %s must be a positive integer.', $key));
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

    private function loader(): DocumentLoader
    {
        return $this->documentLoader ?? new OpenEmrDocumentLoader();
    }

    private function provider(): DocumentExtractionProvider
    {
        return $this->extractionProvider ?? new FixtureExtractionProvider($this->fixtureManifestPath());
    }

    private function fixtureManifestPath(): string
    {
        $configured = getenv('AGENTFORGE_EXTRACTION_FIXTURE_MANIFEST');
        if (is_string($configured) && trim($configured) !== '') {
            return $configured;
        }

        return dirname(__DIR__, 3) . '/agent-forge/fixtures/clinical-document-golden/extraction/manifest.json';
    }
}
