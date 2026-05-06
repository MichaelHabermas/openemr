<?php

/**
 * Contract tests: JSON schema builder vs PHP extraction DTOs (single contract surface).
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/open-emr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Tests\Isolated\AgentForge\Document\Extraction;

use OpenEMR\AgentForge\Document\DocumentType;
use OpenEMR\AgentForge\Document\Extraction\ExtractionProviderResponse;
use OpenEMR\AgentForge\Document\Extraction\JsonSchemaBuilder;
use OpenEMR\AgentForge\Document\Schema\IntakeFormExtraction;
use OpenEMR\AgentForge\Document\Schema\LabPdfExtraction;
use OpenEMR\AgentForge\ResponseGeneration\DraftUsage;
use OpenEMR\AgentForge\StringKeyedArray;
use PHPUnit\Framework\TestCase;

final class ExtractionSchemaContractTest extends TestCase
{
    public function testMinimalLabJsonParsesAndMatchesSchemaRootAndResultKeys(): void
    {
        $json = json_encode($this->minimalLabPayload(), JSON_THROW_ON_ERROR);
        $extraction = LabPdfExtraction::fromJson($json);
        $this->assertSame(DocumentType::LabPdf, $extraction->documentType);
        $this->assertCount(1, $extraction->results);

        $schema = (new JsonSchemaBuilder())->schema(DocumentType::LabPdf);
        $this->assertSchemaObjectRequiredKeys(
            $schema,
            ['doc_type', 'lab_name', 'collected_at', 'results'],
        );
        $items = $this->nestedSchemaItems($schema, ['properties', 'results']);
        $this->assertSchemaObjectRequiredKeys(
            $items,
            [
                'test_name',
                'value',
                'unit',
                'reference_range',
                'collected_at',
                'abnormal_flag',
                'certainty',
                'confidence',
                'citation',
            ],
        );

        $response = ExtractionProviderResponse::fromStrictJson(
            DocumentType::LabPdf,
            $json,
            DraftUsage::fixture(),
        );
        $this->assertTrue($response->schemaValid);
        $this->assertNotNull($response->extraction);
    }

    public function testMinimalIntakeJsonParsesAndMatchesSchemaRootAndFindingKeys(): void
    {
        $json = json_encode($this->minimalIntakePayload(), JSON_THROW_ON_ERROR);
        $extraction = IntakeFormExtraction::fromJson($json);
        $this->assertSame(DocumentType::IntakeForm, $extraction->documentType);
        $this->assertCount(1, $extraction->findings);

        $schema = (new JsonSchemaBuilder())->schema(DocumentType::IntakeForm);
        $this->assertSchemaObjectRequiredKeys(
            $schema,
            ['doc_type', 'form_name', 'findings'],
        );
        $items = $this->nestedSchemaItems($schema, ['properties', 'findings']);
        $this->assertSchemaObjectRequiredKeys(
            $items,
            ['field', 'value', 'certainty', 'confidence', 'citation'],
        );

        $response = ExtractionProviderResponse::fromStrictJson(
            DocumentType::IntakeForm,
            $json,
            DraftUsage::fixture(),
        );
        $this->assertTrue($response->schemaValid);
        $this->assertNotNull($response->extraction);
    }

    /** @return array<string, mixed> */
    private function minimalLabPayload(): array
    {
        return [
            'doc_type' => 'lab_pdf',
            'lab_name' => 'Acme Lab',
            'collected_at' => '2026-04-01',
            'results' => [
                [
                    'test_name' => 'LDL',
                    'value' => '91',
                    'unit' => 'mg/dL',
                    'reference_range' => '<100',
                    'collected_at' => '2026-04-01',
                    'abnormal_flag' => 'normal',
                    'certainty' => 'verified',
                    'confidence' => 0.95,
                    'citation' => [
                        'source_type' => 'lab_pdf',
                        'source_id' => 'doc:1',
                        'page_or_section' => '1',
                        'field_or_chunk_id' => 'r0',
                        'quote_or_value' => 'LDL 91',
                    ],
                ],
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function minimalIntakePayload(): array
    {
        return [
            'doc_type' => 'intake_form',
            'form_name' => 'Intake',
            'findings' => [
                [
                    'field' => 'Allergies',
                    'value' => 'NKDA',
                    'certainty' => 'document_fact',
                    'confidence' => 0.9,
                    'citation' => [
                        'source_type' => 'intake_form',
                        'source_id' => 'doc:2',
                        'page_or_section' => '1',
                        'field_or_chunk_id' => 'allergies',
                        'quote_or_value' => 'NKDA',
                    ],
                ],
            ],
        ];
    }

    /**
     * @param array<string, mixed> $schemaFragment
     * @param list<string>         $expectedRequired
     */
    private function assertSchemaObjectRequiredKeys(array $schemaFragment, array $expectedRequired): void
    {
        $this->assertSame('object', $schemaFragment['type'] ?? null);
        $required = $schemaFragment['required'] ?? null;
        $this->assertIsArray($required);
        sort($required);
        $sorted = $expectedRequired;
        sort($sorted);
        $this->assertSame($sorted, $required, 'JSON schema required keys must match PHP DTO parse contract.');
    }

    /**
     * @param array<string, mixed> $schema
     * @param list<string>         $path
     *
     * @return array<string, mixed>
     */
    private function nestedSchemaItems(array $schema, array $path): array
    {
        return $this->walkSchemaPathToItems($schema, $path);
    }

    /**
     * @param list<string> $path
     *
     * @return array<string, mixed>
     */
    private function walkSchemaPathToItems(mixed $current, array $path): array
    {
        foreach ($path as $segment) {
            if (!is_array($current) || !array_key_exists($segment, $current)) {
                $this->fail('Schema path is missing a required segment.');
            }

            $current = $current[$segment];
        }

        if (!is_array($current)) {
            $this->fail('Schema path did not end on an object.');
        }

        $items = $current['items'] ?? null;
        if (!is_array($items)) {
            $this->fail('Schema is missing a valid items definition.');
        }

        return StringKeyedArray::filter($items);
    }
}
