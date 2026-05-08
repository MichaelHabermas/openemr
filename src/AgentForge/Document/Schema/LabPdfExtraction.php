<?php

/**
 * Structured extraction contract for AgentForge lab PDFs.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\AgentForge\Document\Schema;

use JsonException;
use OpenEMR\AgentForge\Document\DocumentType;

final readonly class LabPdfExtraction
{
    /**
     * @param list<LabResultRow>             $results
     * @param list<PatientIdentityCandidate> $patientIdentity
     */
    public function __construct(
        public DocumentType $documentType,
        public string $labName,
        public string $collectedAt,
        public array $results,
        public array $patientIdentity = [],
    ) {
        if ($documentType !== DocumentType::LabPdf) {
            throw new ExtractionSchemaException('doc_type', 'Expected document type lab_pdf.');
        }
        if ($labName === '') {
            throw new ExtractionSchemaException('lab_name', 'Expected non-empty string.');
        }
        if ($collectedAt === '') {
            throw new ExtractionSchemaException('collected_at', 'Expected non-empty string.');
        }
        if ($results === []) {
            throw new ExtractionSchemaException('results', 'Expected at least one lab result.');
        }
    }

    public static function fromJson(string $json): self
    {
        try {
            $data = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new ExtractionSchemaException('$', 'Invalid JSON: ' . $e->getMessage());
        }

        if (!is_array($data) || array_is_list($data)) {
            throw new ExtractionSchemaException('$', 'Expected object.');
        }

        /** @var array<string, mixed> $data */
        return self::fromArray($data);
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        SchemaReader::assertNoUnknownFields($data, ['doc_type', 'lab_name', 'collected_at', 'patient_identity', 'results'], '$');
        SchemaReader::assertDocumentType(SchemaReader::requiredString($data, 'doc_type', '$'), DocumentType::LabPdf->value, '$.doc_type');

        $rows = [];
        foreach (SchemaReader::requiredList($data, 'results', '$') as $index => $row) {
            if (!is_array($row) || array_is_list($row)) {
                throw new ExtractionSchemaException(SchemaReader::index('$.results', $index), 'Expected object.');
            }

            /** @var array<string, mixed> $row */
            $rows[] = LabResultRow::fromArray($row, SchemaReader::index('$.results', $index));
        }

        $identity = [];
        foreach (SchemaReader::requiredList($data, 'patient_identity', '$') as $index => $candidate) {
            if (!is_array($candidate) || array_is_list($candidate)) {
                throw new ExtractionSchemaException(SchemaReader::index('$.patient_identity', $index), 'Expected object.');
            }

            /** @var array<string, mixed> $candidate */
            $identity[] = PatientIdentityCandidate::fromArray($candidate, SchemaReader::index('$.patient_identity', $index), DocumentSourceType::LabPdf);
        }

        return new self(
            DocumentType::LabPdf,
            SchemaReader::requiredString($data, 'lab_name', '$'),
            SchemaReader::requiredString($data, 'collected_at', '$'),
            $rows,
            $identity,
        );
    }
}
