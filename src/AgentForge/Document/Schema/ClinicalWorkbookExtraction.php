<?php

/**
 * Contract-only extraction schema for XLSX clinical workbook fixtures.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\AgentForge\Document\Schema;

use JsonException;
use OpenEMR\AgentForge\Document\DocumentType;

final readonly class ClinicalWorkbookExtraction
{
    /**
     * @param list<ExtractedClinicalFact>    $facts
     * @param list<PatientIdentityCandidate> $patientIdentity
     */
    public function __construct(
        public DocumentType $documentType,
        public string $workbookName,
        public array $facts,
        public array $patientIdentity = [],
    ) {
        if ($documentType !== DocumentType::ClinicalWorkbook) {
            throw new ExtractionSchemaException('doc_type', 'Expected document type clinical_workbook.');
        }
        if ($workbookName === '') {
            throw new ExtractionSchemaException('workbook_name', 'Expected non-empty string.');
        }
        if ($facts === []) {
            throw new ExtractionSchemaException('facts', 'Expected at least one workbook fact.');
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
        SchemaReader::assertNoUnknownFields($data, ['doc_type', 'workbook_name', 'patient_identity', 'facts'], '$');
        SchemaReader::assertDocumentType(SchemaReader::requiredString($data, 'doc_type', '$'), DocumentType::ClinicalWorkbook->value, '$.doc_type');

        return new self(
            DocumentType::ClinicalWorkbook,
            SchemaReader::requiredString($data, 'workbook_name', '$'),
            self::facts($data),
            self::identity($data),
        );
    }

    /**
     * @param array<string, mixed> $data
     * @return list<ExtractedClinicalFact>
     */
    private static function facts(array $data): array
    {
        $facts = [];
        foreach (SchemaReader::requiredList($data, 'facts', '$') as $index => $fact) {
            if (!is_array($fact) || array_is_list($fact)) {
                throw new ExtractionSchemaException(SchemaReader::index('$.facts', $index), 'Expected object.');
            }
            /** @var array<string, mixed> $fact */
            $facts[] = ExtractedClinicalFact::fromArray($fact, SchemaReader::index('$.facts', $index), DocumentSourceType::ClinicalWorkbook);
        }

        return $facts;
    }

    /**
     * @param array<string, mixed> $data
     * @return list<PatientIdentityCandidate>
     */
    private static function identity(array $data): array
    {
        $identity = [];
        foreach (SchemaReader::requiredList($data, 'patient_identity', '$') as $index => $candidate) {
            if (!is_array($candidate) || array_is_list($candidate)) {
                throw new ExtractionSchemaException(SchemaReader::index('$.patient_identity', $index), 'Expected object.');
            }
            /** @var array<string, mixed> $candidate */
            $identity[] = PatientIdentityCandidate::fromArray($candidate, SchemaReader::index('$.patient_identity', $index), DocumentSourceType::ClinicalWorkbook);
        }

        return $identity;
    }
}
