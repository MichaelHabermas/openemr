<?php

/**
 * Isolated tests for IntakeFormFactMapper draft production and structured value shape.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Tests\Isolated\AgentForge\Document\Mapping;

use DomainException;
use OpenEMR\AgentForge\Document\DocumentType;
use OpenEMR\AgentForge\Document\Mapping\IntakeFormFactMapper;
use OpenEMR\AgentForge\Document\Schema\AbnormalFlag;
use OpenEMR\AgentForge\Document\Schema\Certainty;
use OpenEMR\AgentForge\Document\Schema\DocumentCitation;
use OpenEMR\AgentForge\Document\Schema\DocumentSourceType;
use OpenEMR\AgentForge\Document\Schema\IntakeFormExtraction;
use OpenEMR\AgentForge\Document\Schema\IntakeFormFinding;
use OpenEMR\AgentForge\Document\Schema\LabPdfExtraction;
use OpenEMR\AgentForge\Document\Schema\LabResultRow;
use PHPUnit\Framework\TestCase;

final class IntakeFormFactMapperTest extends TestCase
{
    public function testSupportsOnlyIntakeForm(): void
    {
        $mapper = new IntakeFormFactMapper();

        self::assertTrue($mapper->supports(DocumentType::IntakeForm));
        self::assertFalse($mapper->supports(DocumentType::LabPdf));
        self::assertFalse($mapper->supports(DocumentType::FaxPacket));
    }

    public function testProducesCorrectDraftCount(): void
    {
        $mapper = new IntakeFormFactMapper();
        $extraction = self::extraction([
            self::finding('allergies', 'Penicillin'),
            self::finding('medications', 'Metformin 500 mg'),
            self::finding('conditions', 'Type 2 Diabetes'),
        ]);

        $drafts = $mapper->map($extraction);

        self::assertCount(3, $drafts);
    }

    public function testFieldPathUsesFieldName(): void
    {
        $mapper = new IntakeFormFactMapper();
        $extraction = self::extraction([
            self::finding('allergies', 'Penicillin'),
            self::finding('medications', 'Metformin'),
        ]);

        $drafts = $mapper->map($extraction);

        self::assertSame('allergies', $drafts[0]->fieldPath);
        self::assertSame('medications', $drafts[1]->fieldPath);
    }

    public function testDraftFactTypeAndDisplayLabel(): void
    {
        $mapper = new IntakeFormFactMapper();
        $extraction = self::extraction([self::finding('allergies', 'Penicillin')]);

        $draft = $mapper->map($extraction)[0];

        self::assertSame('intake_finding', $draft->factType);
        self::assertSame('allergies', $draft->displayLabel);
    }

    public function testStructuredValueShapeMatchesPersistMethod(): void
    {
        $mapper = new IntakeFormFactMapper();
        $extraction = self::extraction([self::finding('allergies', 'Penicillin')]);

        $sv = $mapper->map($extraction)[0]->structuredValue;

        self::assertArrayHasKey('display_label', $sv);
        self::assertArrayHasKey('field', $sv);
        self::assertArrayHasKey('value', $sv);
        self::assertArrayHasKey('certainty', $sv);
        self::assertArrayHasKey('confidence', $sv);
        self::assertArrayHasKey('field_path', $sv);
        self::assertSame('allergies', $sv['field']);
        self::assertSame('Penicillin', $sv['value']);
        self::assertSame('document_fact', $sv['certainty']);
        self::assertSame(0.90, $sv['confidence']);
        self::assertSame('allergies', $sv['field_path']);
    }

    public function testFactTextUsesValue(): void
    {
        $mapper = new IntakeFormFactMapper();
        $extraction = self::extraction([self::finding('allergies', 'Penicillin')]);

        $draft = $mapper->map($extraction)[0];

        self::assertSame('Penicillin', $draft->factText);
    }

    public function testCitationAndConfidencePassThrough(): void
    {
        $citation = new DocumentCitation(
            DocumentSourceType::IntakeForm,
            'doc:42',
            'section A',
            'chunk:3',
            'Patient reports Penicillin allergy',
        );
        $finding = new IntakeFormFinding(
            'allergies',
            'Penicillin',
            Certainty::NeedsReview,
            0.45,
            $citation,
        );
        $mapper = new IntakeFormFactMapper();
        $extraction = new IntakeFormExtraction(DocumentType::IntakeForm, 'Test Form', [$finding]);

        $draft = $mapper->map($extraction)[0];

        self::assertSame($citation, $draft->citation);
        self::assertSame(0.45, $draft->confidence);
        self::assertSame(Certainty::NeedsReview, $draft->modelCertainty);
    }

    public function testStructuredValueContainsDisplayLabelKey(): void
    {
        $mapper = new IntakeFormFactMapper();
        $extraction = self::extraction([self::finding('allergies', 'Penicillin')]);

        $sv = $mapper->map($extraction)[0]->structuredValue;

        self::assertArrayHasKey('display_label', $sv);
        self::assertSame('allergies', $sv['display_label']);
    }

    public function testThrowsForWrongExtractionType(): void
    {
        $mapper = new IntakeFormFactMapper();

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('IntakeFormFactMapper requires an IntakeFormExtraction.');

        $mapper->map(
            new LabPdfExtraction(
                DocumentType::LabPdf,
                'Acme Labs',
                '2026-05-01',
                [new LabResultRow(
                    'Potassium',
                    '5.4',
                    'mmol/L',
                    '3.5-5.1',
                    '2026-05-01',
                    AbnormalFlag::High,
                    Certainty::DocumentFact,
                    0.90,
                    self::citation(),
                )],
            ),
        );
    }

    private static function finding(string $field, string $value): IntakeFormFinding
    {
        return new IntakeFormFinding(
            $field,
            $value,
            Certainty::DocumentFact,
            0.90,
            self::citation(),
        );
    }

    /** @param list<IntakeFormFinding> $findings */
    private static function extraction(array $findings): IntakeFormExtraction
    {
        return new IntakeFormExtraction(
            DocumentType::IntakeForm,
            'Test Intake Form',
            $findings,
        );
    }

    private static function citation(): DocumentCitation
    {
        return new DocumentCitation(
            DocumentSourceType::IntakeForm,
            'source:1',
            'page 1',
            'field:1',
            'Patient reports allergy',
        );
    }
}
