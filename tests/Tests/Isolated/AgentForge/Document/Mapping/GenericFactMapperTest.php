<?php

/**
 * Isolated tests for the 4 generic ExtractedClinicalFact-based fact mappers:
 * ReferralDocx, ClinicalWorkbook, FaxPacket, Hl7v2Message.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Tests\Isolated\AgentForge\Document\Mapping;

use DomainException;
use OpenEMR\AgentForge\Document\DocumentType;
use OpenEMR\AgentForge\Document\Mapping\ClinicalWorkbookFactMapper;
use OpenEMR\AgentForge\Document\Mapping\DocumentFactMapper;
use OpenEMR\AgentForge\Document\Mapping\FaxPacketFactMapper;
use OpenEMR\AgentForge\Document\Mapping\Hl7v2MessageFactMapper;
use OpenEMR\AgentForge\Document\Mapping\ReferralDocxFactMapper;
use OpenEMR\AgentForge\Document\Schema\Certainty;
use OpenEMR\AgentForge\Document\Schema\ClinicalWorkbookExtraction;
use OpenEMR\AgentForge\Document\Schema\DocumentCitation;
use OpenEMR\AgentForge\Document\Schema\DocumentSourceType;
use OpenEMR\AgentForge\Document\Schema\ExtractedClinicalFact;
use OpenEMR\AgentForge\Document\Schema\FaxPacketExtraction;
use OpenEMR\AgentForge\Document\Schema\Hl7v2MessageExtraction;
use OpenEMR\AgentForge\Document\Schema\IntakeFormExtraction;
use OpenEMR\AgentForge\Document\Schema\IntakeFormFinding;
use OpenEMR\AgentForge\Document\Schema\ReferralDocxExtraction;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class GenericFactMapperTest extends TestCase
{
    /**
     * @return array<string, array{DocumentFactMapper, DocumentType, DocumentType}>
     *
     * @codeCoverageIgnore Data providers run before coverage instrumentation starts.
     */
    public static function mapperProvider(): array
    {
        return [
            'referral_docx' => [new ReferralDocxFactMapper(), DocumentType::ReferralDocx, DocumentType::IntakeForm],
            'clinical_workbook' => [new ClinicalWorkbookFactMapper(), DocumentType::ClinicalWorkbook, DocumentType::LabPdf],
            'fax_packet' => [new FaxPacketFactMapper(), DocumentType::FaxPacket, DocumentType::ReferralDocx],
            'hl7v2_message' => [new Hl7v2MessageFactMapper(), DocumentType::Hl7v2Message, DocumentType::FaxPacket],
        ];
    }

    #[DataProvider('mapperProvider')]
    public function testSupportsOnlyOwnType(DocumentFactMapper $mapper, DocumentType $supportedType, DocumentType $unsupportedType): void
    {
        self::assertTrue($mapper->supports($supportedType));
        self::assertFalse($mapper->supports($unsupportedType));
    }

    public function testReferralProducesCorrectDrafts(): void
    {
        $mapper = new ReferralDocxFactMapper();
        $extraction = new ReferralDocxExtraction(
            DocumentType::ReferralDocx,
            'Dr. Smith Referral',
            [
                self::fact('referral_reason', 'referral.reason', 'Reason', 'Chronic back pain'),
                self::fact('referring_clinician', 'referral.clinician', 'Clinician', 'Dr. Smith'),
            ],
        );

        $drafts = $mapper->map($extraction);

        self::assertCount(2, $drafts);
        self::assertSame('referral_reason', $drafts[0]->factType);
        self::assertSame('referral.reason', $drafts[0]->fieldPath);
        self::assertSame('Reason', $drafts[0]->displayLabel);
    }

    public function testWorkbookProducesCorrectDrafts(): void
    {
        $mapper = new ClinicalWorkbookFactMapper();
        $extraction = new ClinicalWorkbookExtraction(
            DocumentType::ClinicalWorkbook,
            'Patient Panel',
            [self::fact('lab_result', 'sheet1.A2', 'A1C', '7.2%')],
        );

        $drafts = $mapper->map($extraction);

        self::assertCount(1, $drafts);
        self::assertSame('lab_result', $drafts[0]->factType);
        self::assertSame('sheet1.A2', $drafts[0]->fieldPath);
    }

    public function testFaxPacketProducesCorrectDrafts(): void
    {
        $mapper = new FaxPacketFactMapper();
        $extraction = new FaxPacketExtraction(
            DocumentType::FaxPacket,
            'Fax from clinic',
            [self::fact('clinical_note', 'page1.block2', 'Note', 'Follow up in 2 weeks')],
        );

        $drafts = $mapper->map($extraction);

        self::assertCount(1, $drafts);
        self::assertSame('clinical_note', $drafts[0]->factType);
    }

    public function testHl7v2ProducesCorrectDrafts(): void
    {
        $mapper = new Hl7v2MessageFactMapper();
        $extraction = new Hl7v2MessageExtraction(
            DocumentType::Hl7v2Message,
            'ADT^A01',
            'MSG00001',
            [self::fact('observation', 'OBX.1', 'Heart Rate', '72 bpm')],
        );

        $drafts = $mapper->map($extraction);

        self::assertCount(1, $drafts);
        self::assertSame('observation', $drafts[0]->factType);
        self::assertSame('OBX.1', $drafts[0]->fieldPath);
    }

    public function testStructuredValueShapeMatchesPersistGenericFact(): void
    {
        $mapper = new ReferralDocxFactMapper();
        $extraction = new ReferralDocxExtraction(
            DocumentType::ReferralDocx,
            'Referral',
            [self::fact('diagnosis', 'facts[0]', 'Diagnosis', 'Hypertension')],
        );

        $sv = $mapper->map($extraction)[0]->structuredValue;

        $expectedKeys = ['type', 'label', 'value', 'certainty', 'confidence', 'field_path'];
        self::assertSame($expectedKeys, array_keys($sv));
        self::assertSame('diagnosis', $sv['type']);
        self::assertSame('Diagnosis', $sv['label']);
        self::assertSame('Hypertension', $sv['value']);
        self::assertSame('document_fact', $sv['certainty']);
        self::assertSame(0.90, $sv['confidence']);
        self::assertSame('facts[0]', $sv['field_path']);
    }

    public function testFieldPathUsesFactFieldPathWhenNonEmpty(): void
    {
        $mapper = new ReferralDocxFactMapper();
        $extraction = new ReferralDocxExtraction(
            DocumentType::ReferralDocx,
            'Referral',
            [self::fact('diagnosis', 'referral.dx', 'Diagnosis', 'HTN')],
        );

        $draft = $mapper->map($extraction)[0];

        self::assertSame('referral.dx', $draft->fieldPath);
    }

    public function testFieldPathFallsBackToIndexWhenEmpty(): void
    {
        $mapper = new ReferralDocxFactMapper();
        $extraction = new ReferralDocxExtraction(
            DocumentType::ReferralDocx,
            'Referral',
            [self::fact('diagnosis', '', 'Diagnosis', 'HTN')],
        );

        $draft = $mapper->map($extraction)[0];

        self::assertSame('facts[0]', $draft->fieldPath);
    }

    public function testFactTextCombinesLabelAndValue(): void
    {
        $mapper = new ClinicalWorkbookFactMapper();
        $extraction = new ClinicalWorkbookExtraction(
            DocumentType::ClinicalWorkbook,
            'Panel',
            [self::fact('medication', 'sheet1.B3', 'Medication', 'Lisinopril 10 mg')],
        );

        $draft = $mapper->map($extraction)[0];

        self::assertSame('Medication: Lisinopril 10 mg', $draft->factText);
    }

    public function testFactTextFallsBackToFieldPathWhenBothEmpty(): void
    {
        $citation = new DocumentCitation(
            DocumentSourceType::ReferralDocx,
            'source:1',
            'page 1',
            'field:1',
            'some quote here',
        );
        $fact = new ExtractedClinicalFact(
            'note',
            'custom.path',
            '',
            '',
            Certainty::DocumentFact,
            0.80,
            $citation,
        );
        $mapper = new ReferralDocxFactMapper();
        $extraction = new ReferralDocxExtraction(
            DocumentType::ReferralDocx,
            'Referral',
            [$fact],
        );

        $draft = $mapper->map($extraction)[0];

        self::assertSame('custom.path', $draft->factText);
    }

    public function testThrowsForWrongExtractionType(): void
    {
        $mapper = new ReferralDocxFactMapper();

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('ReferralDocxFactMapper requires a ReferralDocxExtraction.');

        $intakeCitation = new DocumentCitation(
            DocumentSourceType::IntakeForm,
            'source:1',
            'page 1',
            'field:1',
            'some finding quote',
        );
        $mapper->map(
            new IntakeFormExtraction(
                DocumentType::IntakeForm,
                'Form',
                [new IntakeFormFinding('field', 'value', Certainty::DocumentFact, 0.80, $intakeCitation)],
            ),
        );
    }

    public function testClinicalWorkbookThrowsForWrongType(): void
    {
        $mapper = new ClinicalWorkbookFactMapper();

        $this->expectException(DomainException::class);

        $mapper->map(
            new FaxPacketExtraction(
                DocumentType::FaxPacket,
                'Fax',
                [self::fact('note', 'p1', 'Note', 'Text')],
            ),
        );
    }

    public function testFaxPacketThrowsForWrongType(): void
    {
        $mapper = new FaxPacketFactMapper();

        $this->expectException(DomainException::class);

        $mapper->map(
            new Hl7v2MessageExtraction(
                DocumentType::Hl7v2Message,
                'ADT^A01',
                'MSG001',
                [self::fact('obs', 'OBX.1', 'Label', 'Value')],
            ),
        );
    }

    /**
     * Verifies each generic mapper produces a structuredValue with a key that
     * PatientDocumentFactsEvidenceTool::displayLabel() will match.
     * The lookup order is: display_label, test_name, label, field, name.
     */
    public function testStructuredValueContainsDisplayLabelKey(): void
    {
        $lookupKeys = ['display_label', 'test_name', 'label', 'field', 'name'];
        $mappers = [
            'referral' => [new ReferralDocxFactMapper(), new ReferralDocxExtraction(DocumentType::ReferralDocx, 'R', [self::fact('dx', 'f', 'Diagnosis', 'HTN')])],
            'workbook' => [new ClinicalWorkbookFactMapper(), new ClinicalWorkbookExtraction(DocumentType::ClinicalWorkbook, 'W', [self::fact('lab', 'f', 'A1C', '7.2')])],
            'fax' => [new FaxPacketFactMapper(), new FaxPacketExtraction(DocumentType::FaxPacket, 'F', [self::fact('note', 'f', 'Note', 'Text')])],
            'hl7v2' => [new Hl7v2MessageFactMapper(), new Hl7v2MessageExtraction(DocumentType::Hl7v2Message, 'ADT', 'M1', [self::fact('obs', 'f', 'HR', '72')])],
        ];

        foreach ($mappers as $name => [$mapper, $extraction]) {
            $sv = $mapper->map($extraction)[0]->structuredValue;
            $found = false;
            foreach ($lookupKeys as $key) {
                if (isset($sv[$key]) && is_string($sv[$key]) && $sv[$key] !== '') {
                    $found = true;
                    break;
                }
            }
            self::assertTrue($found, sprintf('Mapper "%s" structuredValue has no displayLabel-compatible key. Keys: %s', $name, implode(', ', array_keys($sv))));
        }
    }

    public function testHl7v2ThrowsForWrongType(): void
    {
        $mapper = new Hl7v2MessageFactMapper();

        $this->expectException(DomainException::class);

        $mapper->map(
            new ReferralDocxExtraction(
                DocumentType::ReferralDocx,
                'Referral',
                [self::fact('reason', 'r.1', 'Reason', 'Pain')],
            ),
        );
    }

    private static function fact(string $type, string $fieldPath, string $label, string $value): ExtractedClinicalFact
    {
        return new ExtractedClinicalFact(
            $type,
            $fieldPath,
            $label,
            $value,
            Certainty::DocumentFact,
            0.90,
            self::citation(),
        );
    }

    private static function citation(): DocumentCitation
    {
        return new DocumentCitation(
            DocumentSourceType::ReferralDocx,
            'source:1',
            'page 1',
            'field:1',
            'Relevant clinical quote text',
        );
    }
}
