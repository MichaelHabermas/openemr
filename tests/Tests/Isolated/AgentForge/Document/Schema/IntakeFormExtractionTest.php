<?php

/**
 * Isolated tests for AgentForge intake form extraction schema validation.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Tests\Isolated\AgentForge\Document\Schema;

use OpenEMR\AgentForge\Document\DocumentType;
use OpenEMR\AgentForge\Document\Schema\Certainty;
use OpenEMR\AgentForge\Document\Schema\DocumentSourceType;
use OpenEMR\AgentForge\Document\Schema\ExtractionSchemaException;
use OpenEMR\AgentForge\Document\Schema\IntakeFormExtraction;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class IntakeFormExtractionTest extends TestCase
{
    public function testValidIntakeJsonBuildsReadonlyValueObjects(): void
    {
        $extraction = IntakeFormExtraction::fromJson(json_encode($this->validPayload(), JSON_THROW_ON_ERROR));

        $this->assertSame(DocumentType::IntakeForm, $extraction->documentType);
        $this->assertSame('New Patient Intake', $extraction->formName);
        $this->assertCount(1, $extraction->findings);
        $this->assertSame('chief_complaint', $extraction->findings[0]->field);
        $this->assertSame('chest discomfort', $extraction->findings[0]->value);
        $this->assertSame(Certainty::DocumentFact, $extraction->findings[0]->certainty);
        $this->assertSame(DocumentSourceType::IntakeForm, $extraction->findings[0]->citation->sourceType);
    }

    /**
     * @return list<array{string, array<string, mixed>, string}>
     *
     * @codeCoverageIgnore Data providers run before coverage instrumentation starts.
     */
    public static function invalidPayloadProvider(): array
    {
        $wrongDocumentType = self::validPayload();
        $wrongDocumentType['doc_type'] = 'lab_pdf';

        $missingCitation = self::validPayloadWithoutCitation();

        $badCertainty = self::validPayloadWithCertainty('maybe');

        $badSourceType = self::validPayloadWithSourceType('clipboard');

        return [
            ['wrong doc_type', $wrongDocumentType, '$.doc_type: Expected document type intake_form.'],
            ['missing citation', $missingCitation, '$.findings[0].citation: Missing required field.'],
            ['bad certainty path', $badCertainty, '$.findings[0].certainty: Expected supported certainty.'],
            ['bad source type path', $badSourceType, '$.findings[0].citation.source_type: Expected supported source type.'],
        ];
    }

    /** @param array<string, mixed> $payload */
    #[DataProvider('invalidPayloadProvider')]
    public function testInvalidIntakePayloadsReportFieldPath(string $case, array $payload, string $expectedMessage): void
    {
        $this->expectException(ExtractionSchemaException::class);
        $this->expectExceptionMessage($expectedMessage);

        IntakeFormExtraction::fromArray($payload);
    }

    /**
     * @return array<string, mixed>
     */
    private static function validPayload(): array
    {
        return [
            'doc_type' => 'intake_form',
            'form_name' => 'New Patient Intake',
            'findings' => [
                [
                    'field' => 'chief_complaint',
                    'value' => 'chest discomfort',
                    'certainty' => 'document_fact',
                    'confidence' => 0.82,
                    'citation' => [
                        'source_type' => 'intake_form',
                        'source_id' => 'chief_complaint',
                        'page_or_section' => 'chief complaint',
                        'field_or_chunk_id' => 'chief_complaint',
                        'quote_or_value' => 'chest discomfort',
                        'bounding_box' => [
                            'x' => 0.05,
                            'y' => 0.15,
                            'width' => 0.80,
                            'height' => 0.08,
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
            'doc_type' => 'intake_form',
            'form_name' => 'New Patient Intake',
            'findings' => [[
                'field' => 'chief_complaint',
                'value' => 'chest discomfort',
                'certainty' => 'document_fact',
                'confidence' => 0.82,
            ]],
        ];
    }

    /** @return array<string, mixed> */
    private static function validPayloadWithCertainty(string $certainty): array
    {
        $payload = self::validPayload();
        $payload['findings'] = [[
            'field' => 'chief_complaint',
            'value' => 'chest discomfort',
            'certainty' => $certainty,
            'confidence' => 0.82,
            'citation' => self::validCitation('intake_form'),
        ]];

        return $payload;
    }

    /** @return array<string, mixed> */
    private static function validPayloadWithSourceType(string $sourceType): array
    {
        $payload = self::validPayload();
        $payload['findings'] = [[
            'field' => 'chief_complaint',
            'value' => 'chest discomfort',
            'certainty' => 'document_fact',
            'confidence' => 0.82,
            'citation' => self::validCitation($sourceType),
        ]];

        return $payload;
    }

    /** @return array<string, mixed> */
    private static function validCitation(string $sourceType): array
    {
        return [
            'source_type' => $sourceType,
            'source_id' => 'chief_complaint',
            'page_or_section' => 'chief complaint',
            'field_or_chunk_id' => 'chief_complaint',
            'quote_or_value' => 'chest discomfort',
            'bounding_box' => [
                'x' => 0.05,
                'y' => 0.15,
                'width' => 0.80,
                'height' => 0.08,
            ],
        ];
    }
}
