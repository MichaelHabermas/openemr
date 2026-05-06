<?php

/**
 * Isolated tests for AgentForge lab PDF extraction schema validation.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Tests\Isolated\AgentForge\Document\Schema;

use OpenEMR\AgentForge\Document\DocumentType;
use OpenEMR\AgentForge\Document\Schema\AbnormalFlag;
use OpenEMR\AgentForge\Document\Schema\Certainty;
use OpenEMR\AgentForge\Document\Schema\DocumentSourceType;
use OpenEMR\AgentForge\Document\Schema\ExtractionSchemaException;
use OpenEMR\AgentForge\Document\Schema\LabPdfExtraction;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class LabPdfExtractionTest extends TestCase
{
    public function testValidLabJsonBuildsReadonlyValueObjects(): void
    {
        $extraction = LabPdfExtraction::fromJson(json_encode($this->validPayload(), JSON_THROW_ON_ERROR));

        $this->assertSame(DocumentType::LabPdf, $extraction->documentType);
        $this->assertSame('Acme Reference Lab', $extraction->labName);
        $this->assertCount(1, $extraction->results);
        $this->assertSame('Potassium', $extraction->results[0]->testName);
        $this->assertSame(AbnormalFlag::High, $extraction->results[0]->abnormalFlag);
        $this->assertSame('2026-05-01', $extraction->results[0]->collectedAt);
        $this->assertSame(Certainty::Verified, $extraction->results[0]->certainty);
        $this->assertSame(DocumentSourceType::LabPdf, $extraction->results[0]->citation->sourceType);
        $this->assertNotNull($extraction->results[0]->citation->boundingBox);
        $this->assertSame(0.10, $extraction->results[0]->citation->boundingBox->x);
    }

    /**
     * @return list<array{string, array<string, mixed>, string}>
     *
     * @codeCoverageIgnore Data providers run before coverage instrumentation starts.
     */
    public static function invalidPayloadProvider(): array
    {
        $wrongDocumentType = self::validPayload();
        $wrongDocumentType['doc_type'] = 'intake_form';

        $missingCitation = self::validPayloadWithoutCitation();

        $badBoundingBox = self::validPayloadWithBoundingBoxX(1.2);

        $badConfidence = self::validPayloadWithConfidence(1.1);

        $unknownField = self::validPayloadWithExtraField();

        return [
            ['wrong doc_type', $wrongDocumentType, '$.doc_type: Expected document type lab_pdf.'],
            ['missing citation', $missingCitation, '$.results[0].citation: Missing required field.'],
            ['bad bounding box path', $badBoundingBox, '$.results[0].citation.bounding_box.x: Expected normalized number between 0 and 1.'],
            ['bad confidence path', $badConfidence, '$.results[0].confidence: Expected number between 0 and 1.'],
            ['unknown field path', $unknownField, '$.results[0].extra: Unknown field.'],
        ];
    }

    /** @param array<string, mixed> $payload */
    #[DataProvider('invalidPayloadProvider')]
    public function testInvalidLabPayloadsReportFieldPath(string $case, array $payload, string $expectedMessage): void
    {
        $this->expectException(ExtractionSchemaException::class);
        $this->expectExceptionMessage($expectedMessage);

        LabPdfExtraction::fromArray($payload);
    }

    /**
     * @return array<string, mixed>
     */
    private static function validPayload(): array
    {
        return [
            'doc_type' => 'lab_pdf',
            'lab_name' => 'Acme Reference Lab',
            'collected_at' => '2026-05-01',
            'patient_identity' => [],
            'results' => [
                [
                    'test_name' => 'Potassium',
                    'value' => '5.4',
                    'unit' => 'mmol/L',
                    'reference_range' => '3.5-5.1',
                    'collected_at' => '2026-05-01',
                    'abnormal_flag' => 'high',
                    'certainty' => 'verified',
                    'confidence' => 0.91,
                    'citation' => [
                        'source_type' => 'lab_pdf',
                        'source_id' => 'page:1',
                        'page_or_section' => 'page 1',
                        'field_or_chunk_id' => 'results[0]',
                        'quote_or_value' => 'Potassium 5.4 H mmol/L',
                        'bounding_box' => [
                            'x' => 0.10,
                            'y' => 0.20,
                            'width' => 0.30,
                            'height' => 0.05,
                        ],
                    ],
                ],
            ],
        ];
    }

    /** @return array<string, mixed> */
    private static function validPayloadWithoutCitation(): array
    {
        return [
            'doc_type' => 'lab_pdf',
            'lab_name' => 'Acme Reference Lab',
            'collected_at' => '2026-05-01',
            'patient_identity' => [],
            'results' => [[
                'test_name' => 'Potassium',
                'value' => '5.4',
                'unit' => 'mmol/L',
                'reference_range' => '3.5-5.1',
                'collected_at' => '2026-05-01',
                'abnormal_flag' => 'high',
                'certainty' => 'verified',
                'confidence' => 0.91,
            ]],
        ];
    }

    /** @return array<string, mixed> */
    private static function validPayloadWithBoundingBoxX(float $x): array
    {
        $payload = self::validPayload();
        $payload['results'] = [[
            'test_name' => 'Potassium',
            'value' => '5.4',
            'unit' => 'mmol/L',
            'reference_range' => '3.5-5.1',
            'collected_at' => '2026-05-01',
            'abnormal_flag' => 'high',
            'certainty' => 'verified',
            'confidence' => 0.91,
            'citation' => [
                'source_type' => 'lab_pdf',
                'source_id' => 'documents:123',
                'page_or_section' => 'page 1',
                'field_or_chunk_id' => 'results[0]',
                'quote_or_value' => 'Potassium 5.4',
                'bounding_box' => ['x' => $x, 'y' => 0.20, 'width' => 0.30, 'height' => 0.10],
            ],
        ]];

        return $payload;
    }

    /** @return array<string, mixed> */
    private static function validPayloadWithConfidence(float $confidence): array
    {
        $payload = self::validPayload();
        $payload['results'] = [[
            'test_name' => 'Potassium',
            'value' => '5.4',
            'unit' => 'mmol/L',
            'reference_range' => '3.5-5.1',
            'collected_at' => '2026-05-01',
            'abnormal_flag' => 'high',
            'certainty' => 'verified',
            'confidence' => $confidence,
            'citation' => self::validCitation(),
        ]];

        return $payload;
    }

    /** @return array<string, mixed> */
    private static function validPayloadWithExtraField(): array
    {
        $payload = self::validPayload();
        $payload['results'] = [[
            'test_name' => 'Potassium',
            'value' => '5.4',
            'unit' => 'mmol/L',
            'reference_range' => '3.5-5.1',
            'collected_at' => '2026-05-01',
            'abnormal_flag' => 'high',
            'certainty' => 'verified',
            'confidence' => 0.91,
            'citation' => self::validCitation(),
            'extra' => 'not allowed',
        ]];

        return $payload;
    }

    /** @return array<string, mixed> */
    private static function validCitation(): array
    {
        return [
            'source_type' => 'lab_pdf',
            'source_id' => 'documents:123',
            'page_or_section' => 'page 1',
            'field_or_chunk_id' => 'results[0]',
            'quote_or_value' => 'Potassium 5.4',
            'bounding_box' => ['x' => 0.10, 'y' => 0.20, 'width' => 0.30, 'height' => 0.10],
        ];
    }
}
