<?php

/**
 * Structured extraction contract for AgentForge intake forms.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\AgentForge\Document\Schema;

use JsonException;
use OpenEMR\AgentForge\Document\DocumentType;

final readonly class IntakeFormExtraction
{
    /**
     * @param list<IntakeFormFinding> $findings
     */
    public function __construct(
        public DocumentType $documentType,
        public string $formName,
        public array $findings,
    ) {
        if ($documentType !== DocumentType::IntakeForm) {
            throw new ExtractionSchemaException('doc_type', 'Expected document type intake_form.');
        }
        if ($formName === '') {
            throw new ExtractionSchemaException('form_name', 'Expected non-empty string.');
        }
        if ($findings === []) {
            throw new ExtractionSchemaException('findings', 'Expected at least one intake finding.');
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
        SchemaReader::assertNoUnknownFields($data, ['doc_type', 'form_name', 'findings'], '$');
        SchemaReader::assertDocumentType(SchemaReader::requiredString($data, 'doc_type', '$'), DocumentType::IntakeForm->value, '$.doc_type');

        $findings = [];
        foreach (SchemaReader::requiredList($data, 'findings', '$') as $index => $finding) {
            if (!is_array($finding) || array_is_list($finding)) {
                throw new ExtractionSchemaException(SchemaReader::index('$.findings', $index), 'Expected object.');
            }

            /** @var array<string, mixed> $finding */
            $findings[] = IntakeFormFinding::fromArray($finding, SchemaReader::index('$.findings', $index));
        }

        return new self(
            DocumentType::IntakeForm,
            SchemaReader::requiredString($data, 'form_name', '$'),
            $findings,
        );
    }
}
