<?php

/**
 * Isolated tests for CertaintyClassifier::classifyDraft() and draft chart destination rules.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Tests\Isolated\AgentForge\Document\Mapping;

use OpenEMR\AgentForge\Document\DocumentType;
use OpenEMR\AgentForge\Document\Mapping\DocumentFactDraft;
use OpenEMR\AgentForge\Document\Schema\Certainty;
use OpenEMR\AgentForge\Document\Schema\CertaintyClassifier;
use OpenEMR\AgentForge\Document\Schema\DocumentCitation;
use OpenEMR\AgentForge\Document\Schema\DocumentSourceType;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class CertaintyClassifierDraftTest extends TestCase
{
    public function testModelNeedsReviewOverridesHighConfidence(): void
    {
        $classifier = new CertaintyClassifier();

        $draft = self::draft(
            factType: 'lab_result',
            quote: 'Potassium 5.4 H',
            modelCertainty: Certainty::NeedsReview,
            confidence: 0.99,
            structuredValue: ['test_name' => 'Potassium', 'value' => '5.4', 'unit' => 'mmol/L'],
        );

        self::assertSame(Certainty::NeedsReview, $classifier->classifyDraft(DocumentType::LabPdf, $draft));
    }

    /**
     * @return array<string, array{string, Certainty}>
     *
     * @codeCoverageIgnore Data providers run before coverage instrumentation starts.
     */
    public static function weakQuoteProvider(): array
    {
        return [
            'too short (2 chars)' => ['ab', Certainty::NeedsReview],
            'digits only' => ['42', Certainty::NeedsReview],
            'whitespace padded short' => ['  x ', Certainty::NeedsReview],
        ];
    }

    #[DataProvider('weakQuoteProvider')]
    public function testWeakQuotesForcesNeedsReview(string $quote, Certainty $expected): void
    {
        $classifier = new CertaintyClassifier();

        $draft = self::draft(
            factType: 'lab_result',
            quote: $quote,
            modelCertainty: Certainty::Verified,
            confidence: 0.99,
            structuredValue: ['test_name' => 'Potassium', 'value' => '5.4', 'unit' => 'mmol/L'],
        );

        self::assertSame($expected, $classifier->classifyDraft(DocumentType::LabPdf, $draft));
    }

    public function testLowConfidenceForcesNeedsReview(): void
    {
        $classifier = new CertaintyClassifier();

        $draft = self::draft(
            factType: 'lab_result',
            quote: 'Potassium 5.4 H',
            modelCertainty: Certainty::Verified,
            confidence: 0.49,
            structuredValue: ['test_name' => 'Potassium', 'value' => '5.4', 'unit' => 'mmol/L'],
        );

        self::assertSame(Certainty::NeedsReview, $classifier->classifyDraft(DocumentType::LabPdf, $draft));
    }

    public function testHighConfidenceLabDraftWithChartDestinationVerifies(): void
    {
        $classifier = new CertaintyClassifier();

        $draft = self::draft(
            factType: 'lab_result',
            quote: 'Potassium 5.4 H',
            modelCertainty: Certainty::DocumentFact,
            confidence: 0.90,
            structuredValue: ['test_name' => 'Potassium', 'value' => '5.4', 'unit' => 'mmol/L'],
        );

        self::assertSame(Certainty::Verified, $classifier->classifyDraft(DocumentType::LabPdf, $draft));
    }

    public function testMediumConfidenceStaysDocumentFact(): void
    {
        $classifier = new CertaintyClassifier();

        $draft = self::draft(
            factType: 'lab_result',
            quote: 'Potassium 5.4 H',
            modelCertainty: Certainty::DocumentFact,
            confidence: 0.70,
            structuredValue: ['test_name' => 'Potassium', 'value' => '5.4', 'unit' => 'mmol/L'],
        );

        self::assertSame(Certainty::DocumentFact, $classifier->classifyDraft(DocumentType::LabPdf, $draft));
    }

    /**
     * @return array<string, array{DocumentType, string, array<string, mixed>, Certainty}>
     *
     * @codeCoverageIgnore Data providers run before coverage instrumentation starts.
     */
    public static function chartDestinationProvider(): array
    {
        return [
            'lab: complete result verifies' => [
                DocumentType::LabPdf,
                'lab_result',
                ['test_name' => 'LDL', 'value' => '120', 'unit' => 'mg/dL'],
                Certainty::Verified,
            ],
            'lab: missing unit stays document_fact' => [
                DocumentType::LabPdf,
                'lab_result',
                ['test_name' => 'LDL', 'value' => '120', 'unit' => ''],
                Certainty::DocumentFact,
            ],
            'lab: wrong factType stays document_fact' => [
                DocumentType::LabPdf,
                'some_other_type',
                ['test_name' => 'LDL', 'value' => '120', 'unit' => 'mg/dL'],
                Certainty::DocumentFact,
            ],
            'intake: allergy keyword verifies' => [
                DocumentType::IntakeForm,
                'intake_finding',
                ['field' => 'allergies', 'value' => 'Penicillin'],
                Certainty::Verified,
            ],
            'intake: medication keyword verifies' => [
                DocumentType::IntakeForm,
                'intake_finding',
                ['field' => 'current_medications', 'value' => 'Metformin'],
                Certainty::Verified,
            ],
            'intake: family history verifies' => [
                DocumentType::IntakeForm,
                'intake_finding',
                ['field' => 'family_history', 'value' => 'Diabetes'],
                Certainty::Verified,
            ],
            'intake: unmapped field stays document_fact' => [
                DocumentType::IntakeForm,
                'intake_finding',
                ['field' => 'preferred_contact', 'value' => 'email'],
                Certainty::DocumentFact,
            ],
            'intake: wrong factType stays document_fact' => [
                DocumentType::IntakeForm,
                'not_intake_finding',
                ['field' => 'allergies', 'value' => 'Penicillin'],
                Certainty::DocumentFact,
            ],
            'referral: referral_reason verifies' => [
                DocumentType::ReferralDocx,
                'referral_reason',
                [],
                Certainty::Verified,
            ],
            'referral: specialist verifies' => [
                DocumentType::ReferralDocx,
                'specialist_name',
                [],
                Certainty::Verified,
            ],
            'referral: diagnosis verifies' => [
                DocumentType::ReferralDocx,
                'diagnosis_primary',
                [],
                Certainty::Verified,
            ],
            'referral: unmapped stays document_fact' => [
                DocumentType::ReferralDocx,
                'office_phone',
                [],
                Certainty::DocumentFact,
            ],
            'workbook: lab verifies' => [
                DocumentType::ClinicalWorkbook,
                'lab_result',
                [],
                Certainty::Verified,
            ],
            'workbook: medication verifies' => [
                DocumentType::ClinicalWorkbook,
                'medication_list',
                [],
                Certainty::Verified,
            ],
            'workbook: care_gap verifies' => [
                DocumentType::ClinicalWorkbook,
                'care_gap_screening',
                [],
                Certainty::Verified,
            ],
            'workbook: observation verifies' => [
                DocumentType::ClinicalWorkbook,
                'observation_vitals',
                [],
                Certainty::Verified,
            ],
            'workbook: unmapped stays document_fact' => [
                DocumentType::ClinicalWorkbook,
                'billing_code',
                [],
                Certainty::DocumentFact,
            ],
            'fax: always stays document_fact' => [
                DocumentType::FaxPacket,
                'referral_reason',
                [],
                Certainty::DocumentFact,
            ],
            'hl7v2: observation verifies' => [
                DocumentType::Hl7v2Message,
                'observation_result',
                [],
                Certainty::Verified,
            ],
            'hl7v2: demographics verifies' => [
                DocumentType::Hl7v2Message,
                'demographics_pid',
                [],
                Certainty::Verified,
            ],
            'hl7v2: visit verifies' => [
                DocumentType::Hl7v2Message,
                'visit_admit',
                [],
                Certainty::Verified,
            ],
            'hl7v2: unmapped stays document_fact' => [
                DocumentType::Hl7v2Message,
                'insurance_segment',
                [],
                Certainty::DocumentFact,
            ],
        ];
    }

    /**
     * @param array<string, mixed> $extraStructuredValue
     */
    #[DataProvider('chartDestinationProvider')]
    public function testDraftMapsToChartDestination(
        DocumentType $docType,
        string $factType,
        array $extraStructuredValue,
        Certainty $expected,
    ): void {
        $classifier = new CertaintyClassifier();

        $draft = self::draft(
            factType: $factType,
            quote: 'Sufficient clinical quote text',
            modelCertainty: Certainty::DocumentFact,
            confidence: 0.90,
            structuredValue: $extraStructuredValue,
        );

        self::assertSame($expected, $classifier->classifyDraft($docType, $draft));
    }

    /**
     * @return array<string, array{float, Certainty}>
     *
     * @codeCoverageIgnore Data providers run before coverage instrumentation starts.
     */
    public static function boundaryThresholdProvider(): array
    {
        return [
            'at verified threshold (0.85)' => [0.85, Certainty::Verified],
            'just below verified (0.84999)' => [0.84999, Certainty::DocumentFact],
            'at document_fact threshold (0.50)' => [0.50, Certainty::DocumentFact],
            'just below document_fact (0.49999)' => [0.49999, Certainty::NeedsReview],
        ];
    }

    #[DataProvider('boundaryThresholdProvider')]
    public function testBoundaryThresholds(float $confidence, Certainty $expected): void
    {
        $classifier = new CertaintyClassifier();

        $draft = self::draft(
            factType: 'lab_result',
            quote: 'Potassium 5.4 H mmol/L',
            modelCertainty: Certainty::DocumentFact,
            confidence: $confidence,
            structuredValue: ['test_name' => 'Potassium', 'value' => '5.4', 'unit' => 'mmol/L'],
        );

        self::assertSame($expected, $classifier->classifyDraft(DocumentType::LabPdf, $draft));
    }

    /**
     * @param array<string, mixed> $structuredValue
     */
    private static function draft(
        string $factType,
        string $quote,
        Certainty $modelCertainty,
        float $confidence,
        array $structuredValue = [],
    ): DocumentFactDraft {
        return new DocumentFactDraft(
            factType: $factType,
            fieldPath: 'test[0]',
            displayLabel: 'Test Label',
            factText: 'Test fact text',
            structuredValue: $structuredValue,
            citation: new DocumentCitation(
                DocumentSourceType::LabPdf,
                'source:1',
                'page 1',
                'field:1',
                $quote,
            ),
            modelCertainty: $modelCertainty,
            confidence: $confidence,
        );
    }
}
