<?php

/**
 * Isolated tests for LabPdfFactMapper draft production and structured value shape.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Tests\Isolated\AgentForge\Document\Mapping;

use DomainException;
use OpenEMR\AgentForge\Document\DocumentType;
use OpenEMR\AgentForge\Document\Mapping\LabPdfFactMapper;
use OpenEMR\AgentForge\Document\Schema\AbnormalFlag;
use OpenEMR\AgentForge\Document\Schema\Certainty;
use OpenEMR\AgentForge\Document\Schema\DocumentCitation;
use OpenEMR\AgentForge\Document\Schema\DocumentSourceType;
use OpenEMR\AgentForge\Document\Schema\IntakeFormExtraction;
use OpenEMR\AgentForge\Document\Schema\IntakeFormFinding;
use OpenEMR\AgentForge\Document\Schema\LabPdfExtraction;
use OpenEMR\AgentForge\Document\Schema\LabResultRow;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class LabPdfFactMapperTest extends TestCase
{
    public function testSupportsOnlyLabPdf(): void
    {
        $mapper = new LabPdfFactMapper();

        self::assertTrue($mapper->supports(DocumentType::LabPdf));
        self::assertFalse($mapper->supports(DocumentType::IntakeForm));
        self::assertFalse($mapper->supports(DocumentType::ReferralDocx));
    }

    public function testProducesCorrectDraftCountFromMultipleRows(): void
    {
        $mapper = new LabPdfFactMapper();
        $extraction = new LabPdfExtraction(
            DocumentType::LabPdf,
            'Acme Labs',
            '2026-05-01',
            [
                self::labRow('Potassium', '5.4', 'mmol/L', '3.5-5.1', AbnormalFlag::High),
                self::labRow('Sodium', '140', 'mmol/L', '136-145', AbnormalFlag::Normal),
                self::labRow('Glucose', '110', 'mg/dL', '70-100', AbnormalFlag::High),
            ],
        );

        $drafts = $mapper->map($extraction);

        self::assertCount(3, $drafts);
    }

    public function testDraftFieldPathUsesIndexFormat(): void
    {
        $mapper = new LabPdfFactMapper();
        $extraction = new LabPdfExtraction(
            DocumentType::LabPdf,
            'Acme Labs',
            '2026-05-01',
            [
                self::labRow('Potassium', '5.4', 'mmol/L', '3.5-5.1', AbnormalFlag::High),
                self::labRow('Sodium', '140', 'mmol/L', '136-145', AbnormalFlag::Normal),
            ],
        );

        $drafts = $mapper->map($extraction);

        self::assertSame('results[0]', $drafts[0]->fieldPath);
        self::assertSame('results[1]', $drafts[1]->fieldPath);
    }

    public function testDraftFactTypeAndDisplayLabel(): void
    {
        $mapper = new LabPdfFactMapper();
        $extraction = new LabPdfExtraction(
            DocumentType::LabPdf,
            'Acme Labs',
            '2026-05-01',
            [self::labRow('Potassium', '5.4', 'mmol/L', '3.5-5.1', AbnormalFlag::High)],
        );

        $draft = $mapper->map($extraction)[0];

        self::assertSame('lab_result', $draft->factType);
        self::assertSame('Potassium', $draft->displayLabel);
    }

    public function testStructuredValueShapeMatchesPersistMethod(): void
    {
        $mapper = new LabPdfFactMapper();
        $extraction = new LabPdfExtraction(
            DocumentType::LabPdf,
            'Acme Labs',
            '2026-05-01',
            [self::labRow('Potassium', '5.4', 'mmol/L', '3.5-5.1', AbnormalFlag::High)],
        );

        $sv = $mapper->map($extraction)[0]->structuredValue;

        $expectedKeys = ['test_name', 'value', 'unit', 'reference_range', 'collected_at', 'abnormal_flag', 'certainty', 'confidence', 'field_path'];
        self::assertSame($expectedKeys, array_keys($sv));
        self::assertSame('Potassium', $sv['test_name']);
        self::assertSame('5.4', $sv['value']);
        self::assertSame('mmol/L', $sv['unit']);
        self::assertSame('3.5-5.1', $sv['reference_range']);
        self::assertSame('2026-05-01', $sv['collected_at']);
        self::assertSame('high', $sv['abnormal_flag']);
        self::assertSame('document_fact', $sv['certainty']);
        self::assertSame(0.90, $sv['confidence']);
        self::assertSame('results[0]', $sv['field_path']);
    }

    /**
     * @return array<string, array{string, string, string}>
     *
     * @codeCoverageIgnore Data providers run before coverage instrumentation starts.
     */
    public static function displayLabValueProvider(): array
    {
        return [
            'normal value with unit' => ['5.4', 'mmol/L', '5.4 mmol/L'],
            'value already ends with unit' => ['5.4 mmol/L', 'mmol/L', '5.4 mmol/L'],
            'empty unit' => ['5.4', '', '5.4'],
        ];
    }

    #[DataProvider('displayLabValueProvider')]
    public function testDisplayLabValueDeduplication(string $value, string $unit, string $expectedDisplay): void
    {
        $mapper = new LabPdfFactMapper();
        $extraction = new LabPdfExtraction(
            DocumentType::LabPdf,
            'Acme Labs',
            '2026-05-01',
            [self::labRow('Potassium', $value, $unit, '3.5-5.1', AbnormalFlag::Normal)],
        );

        $draft = $mapper->map($extraction)[0];

        self::assertStringContainsString($expectedDisplay, $draft->factText);
    }

    public function testCitationAndConfidencePassThrough(): void
    {
        $citation = new DocumentCitation(
            DocumentSourceType::LabPdf,
            'doc:99',
            'page 3',
            'chunk:7',
            'Potassium 5.4 H',
        );
        $row = new LabResultRow(
            'Potassium',
            '5.4',
            'mmol/L',
            '3.5-5.1',
            '2026-05-01',
            AbnormalFlag::High,
            Certainty::NeedsReview,
            0.42,
            $citation,
        );
        $mapper = new LabPdfFactMapper();
        $extraction = new LabPdfExtraction(DocumentType::LabPdf, 'Acme Labs', '2026-05-01', [$row]);

        $draft = $mapper->map($extraction)[0];

        self::assertSame($citation, $draft->citation);
        self::assertSame(0.42, $draft->confidence);
        self::assertSame(Certainty::NeedsReview, $draft->modelCertainty);
    }

    public function testStructuredValueContainsDisplayLabelCompatibleKey(): void
    {
        $mapper = new LabPdfFactMapper();
        $extraction = new LabPdfExtraction(
            DocumentType::LabPdf,
            'Acme Labs',
            '2026-05-01',
            [self::labRow('Potassium', '5.4', 'mmol/L', '3.5-5.1', AbnormalFlag::High)],
        );

        $sv = $mapper->map($extraction)[0]->structuredValue;

        self::assertArrayHasKey('test_name', $sv);
        self::assertSame('Potassium', $sv['test_name']);
    }

    public function testThrowsForWrongExtractionType(): void
    {
        $mapper = new LabPdfFactMapper();

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('LabPdfFactMapper requires a LabPdfExtraction.');

        $mapper->map(
            new IntakeFormExtraction(
                DocumentType::IntakeForm,
                'Test Form',
                [new IntakeFormFinding(
                    'allergies',
                    'Penicillin',
                    Certainty::DocumentFact,
                    0.90,
                    self::citation(DocumentSourceType::IntakeForm),
                )],
            ),
        );
    }

    private static function labRow(
        string $testName,
        string $value,
        string $unit,
        string $referenceRange,
        AbnormalFlag $abnormalFlag,
    ): LabResultRow {
        return new LabResultRow(
            $testName,
            $value,
            $unit,
            $referenceRange,
            '2026-05-01',
            $abnormalFlag,
            Certainty::DocumentFact,
            0.90,
            self::citation(DocumentSourceType::LabPdf),
        );
    }

    private static function citation(DocumentSourceType $sourceType): DocumentCitation
    {
        return new DocumentCitation(
            $sourceType,
            'source:1',
            'page 1',
            'field:1',
            'Potassium 5.4 H',
        );
    }
}
