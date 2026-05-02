<?php

/**
 * Isolated tests for AgentForge deterministic draft verification.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Tests\Isolated\AgentForge;

use OpenEMR\AgentForge\Evidence\EvidenceBundle;
use OpenEMR\AgentForge\Evidence\EvidenceBundleItem;
use OpenEMR\AgentForge\ResponseGeneration\DraftClaim;
use OpenEMR\AgentForge\ResponseGeneration\DraftResponse;
use OpenEMR\AgentForge\ResponseGeneration\DraftSentence;
use OpenEMR\AgentForge\ResponseGeneration\DraftUsage;
use OpenEMR\AgentForge\Verification\DraftVerifier;
use PHPUnit\Framework\TestCase;

final class DraftVerifierTest extends TestCase
{
    public function testSupportedClaimPassesWithCitation(): void
    {
        $result = (new DraftVerifier())->verify(
            $this->draft('Hemoglobin A1c: 7.4 %', ['lab:procedure_result/a1c@2026-04-10']),
            $this->bundle(),
        );

        $this->assertTrue($result->passed);
        $this->assertSame(['s1'], $result->verifiedSentenceIds);
        $this->assertSame(['lab:procedure_result/a1c@2026-04-10'], $result->citations);
    }

    public function testUnsupportedClaimDoesNotReachFinalAnswer(): void
    {
        $result = (new DraftVerifier())->verify(
            $this->draft('Urine microalbumin: normal', ['lab:procedure_result/missing@2026-04-10']),
            $this->bundle(),
        );

        $this->assertFalse($result->passed);
        $this->assertSame([], $result->verifiedSentenceIds);
        $this->assertStringContainsString('could not be verified', $result->refusalsOrWarnings[0]);
        $this->assertStringNotContainsString('Urine microalbumin', $result->refusalsOrWarnings[0]);
    }

    public function testFabricatedMedicationFactIsBlocked(): void
    {
        $result = (new DraftVerifier())->verify(
            $this->draft('Metformin ER 1000 mg: Take twice daily', ['medication:prescriptions/rx-metformin@2026-03-15']),
            new EvidenceBundle([
                new EvidenceBundleItem(
                    'medication',
                    'medication:prescriptions/rx-metformin@2026-03-15',
                    '2026-03-15',
                    'Metformin ER 500 mg',
                    'Take 1 tablet by mouth daily with evening meal',
                ),
            ]),
        );

        $this->assertFalse($result->passed);
    }

    public function testMislabeledPatientFactWithoutCitationIsBlocked(): void
    {
        foreach ([DraftClaim::TYPE_WARNING, DraftClaim::TYPE_MISSING_DATA, DraftClaim::TYPE_REFUSAL] as $type) {
            $result = (new DraftVerifier())->verify(
                new DraftResponse(
                    [new DraftSentence('s1', 'Hemoglobin A1c: 7.4 %')],
                    [new DraftClaim('Hemoglobin A1c: 7.4 %', $type, [], 's1')],
                    [],
                    [],
                    DraftUsage::fixture(),
                ),
                $this->bundle(),
            );

            $this->assertFalse($result->passed, sprintf('Expected %s claim to be grounded.', $type));
        }
    }

    public function testMislabeledPatientFactWithCorrectCitationIsVerified(): void
    {
        $result = (new DraftVerifier())->verify(
            new DraftResponse(
                [new DraftSentence('s1', 'Hemoglobin A1c: 7.4 % [lab:procedure_result/a1c@2026-04-10]')],
                [
                    new DraftClaim(
                        'Hemoglobin A1c: 7.4 %',
                        DraftClaim::TYPE_WARNING,
                        ['lab:procedure_result/a1c@2026-04-10'],
                        's1',
                    ),
                ],
                [],
                [],
                DraftUsage::fixture(),
            ),
            $this->bundle(),
        );

        $this->assertTrue($result->passed);
        $this->assertSame(['lab:procedure_result/a1c@2026-04-10'], $result->citations);
    }

    public function testSourceIdWithMismatchedValueIsBlocked(): void
    {
        $result = (new DraftVerifier())->verify(
            $this->draft('Hemoglobin A1c: 8.2 %', ['lab:procedure_result/a1c@2026-04-10']),
            $this->bundle(),
        );

        $this->assertFalse($result->passed);
    }

    public function testUnsupportedTailIsBlockedEvenWhenClaimTextIsGrounded(): void
    {
        $result = (new DraftVerifier())->verify(
            new DraftResponse(
                [new DraftSentence('s1', 'Hemoglobin A1c: 7.4 %. Kidney function is stable.')],
                [
                    new DraftClaim(
                        'Hemoglobin A1c: 7.4 %',
                        DraftClaim::TYPE_PATIENT_FACT,
                        ['lab:procedure_result/a1c@2026-04-10'],
                        's1',
                    ),
                ],
                [],
                [],
                DraftUsage::fixture(),
            ),
            $this->bundle(),
        );

        $this->assertFalse($result->passed);
    }

    public function testPartiallySupportedClaimIsBlocked(): void
    {
        $result = (new DraftVerifier())->verify(
            $this->draft(
                'Hemoglobin A1c: 7.4 % and LDL: 120 mg/dL',
                [
                    'lab:procedure_result/a1c@2026-04-10',
                    'lab:procedure_result/ldl@2026-04-10',
                ],
            ),
            $this->bundle(),
        );

        $this->assertFalse($result->passed);
    }

    public function testUnsupportedClaimRejectsWholeSentenceEvenWhenAnotherClaimIsSupported(): void
    {
        $result = (new DraftVerifier())->verify(
            new DraftResponse(
                [new DraftSentence('s1', 'Hemoglobin A1c: 7.4 %. Urine microalbumin: normal.')],
                [
                    new DraftClaim(
                        'Hemoglobin A1c: 7.4 %',
                        DraftClaim::TYPE_PATIENT_FACT,
                        ['lab:procedure_result/a1c@2026-04-10'],
                        's1',
                    ),
                    new DraftClaim(
                        'Urine microalbumin: normal',
                        DraftClaim::TYPE_PATIENT_FACT,
                        ['lab:procedure_result/missing@2026-04-10'],
                        's1',
                    ),
                ],
                [],
                [],
                DraftUsage::fixture(),
            ),
            $this->bundle(),
        );

        $this->assertFalse($result->passed);
        $this->assertSame([], $result->verifiedSentenceIds);
    }

    public function testMultipleGroundedClaimsMayCoverOneDisplayedSentence(): void
    {
        $result = (new DraftVerifier())->verify(
            new DraftResponse(
                [new DraftSentence('s1', 'Hemoglobin A1c: 7.4 % and Glucose: 126 mg/dL.')],
                [
                    new DraftClaim(
                        'Hemoglobin A1c: 7.4 %',
                        DraftClaim::TYPE_PATIENT_FACT,
                        ['lab:procedure_result/a1c@2026-04-10'],
                        's1',
                    ),
                    new DraftClaim(
                        'Glucose: 126 mg/dL',
                        DraftClaim::TYPE_PATIENT_FACT,
                        ['lab:procedure_result/glucose@2026-04-10'],
                        's1',
                    ),
                ],
                [],
                [],
                DraftUsage::fixture(),
            ),
            new EvidenceBundle([
                new EvidenceBundleItem(
                    'lab',
                    'lab:procedure_result/a1c@2026-04-10',
                    '2026-04-10',
                    'Hemoglobin A1c',
                    '7.4 %',
                ),
                new EvidenceBundleItem(
                    'lab',
                    'lab:procedure_result/glucose@2026-04-10',
                    '2026-04-10',
                    'Glucose',
                    '126 mg/dL',
                ),
            ]),
        );

        $this->assertTrue($result->passed);
        $this->assertSame(['s1'], $result->verifiedSentenceIds);
    }

    public function testGroundedMedicationListMayUseNonFactualLeadIn(): void
    {
        $result = (new DraftVerifier())->verify(
            new DraftResponse(
                [
                    new DraftSentence(
                        's1',
                        'Active prescriptions include Metformin ER 500 mg: Take 1 tablet by mouth daily with evening meal and Lisinopril 10 mg: Take 1 tablet by mouth daily.',
                    ),
                ],
                [
                    new DraftClaim(
                        'Metformin ER 500 mg: Take 1 tablet by mouth daily with evening meal',
                        DraftClaim::TYPE_PATIENT_FACT,
                        ['medication:prescriptions/af-rx-metformin@2026-03-15'],
                        's1',
                    ),
                    new DraftClaim(
                        'Lisinopril 10 mg: Take 1 tablet by mouth daily',
                        DraftClaim::TYPE_PATIENT_FACT,
                        ['medication:prescriptions/af-rx-lisinopril@2026-03-15'],
                        's1',
                    ),
                ],
                [],
                [],
                DraftUsage::fixture(),
            ),
            new EvidenceBundle([
                new EvidenceBundleItem(
                    'medication',
                    'medication:prescriptions/af-rx-metformin@2026-03-15',
                    '2026-03-15',
                    'Metformin ER 500 mg',
                    'Take 1 tablet by mouth daily with evening meal',
                ),
                new EvidenceBundleItem(
                    'medication',
                    'medication:prescriptions/af-rx-lisinopril@2026-03-15',
                    '2026-03-15',
                    'Lisinopril 10 mg',
                    'Take 1 tablet by mouth daily',
                ),
            ]),
        );

        $this->assertTrue($result->passed);
        $this->assertSame(['s1'], $result->verifiedSentenceIds);
    }

    public function testGroundedMedicationNameListCanOmitInstructions(): void
    {
        $result = (new DraftVerifier())->verify(
            new DraftResponse(
                [new DraftSentence('s1', 'The active medications are Metformin ER 500 mg and Lisinopril 10 mg.')],
                [
                    new DraftClaim(
                        'The active medication is Metformin ER 500 mg: Take 1 tablet by mouth daily with evening meal.',
                        DraftClaim::TYPE_PATIENT_FACT,
                        ['medication:prescriptions/af-rx-metformin@2026-03-15'],
                        's1',
                    ),
                    new DraftClaim(
                        'The active medication is Lisinopril 10 mg: Take 1 tablet by mouth daily.',
                        DraftClaim::TYPE_PATIENT_FACT,
                        ['medication:prescriptions/af-rx-lisinopril@2026-03-15'],
                        's1',
                    ),
                ],
                [],
                [],
                DraftUsage::fixture(),
            ),
            new EvidenceBundle([
                new EvidenceBundleItem(
                    'medication',
                    'medication:prescriptions/af-rx-metformin@2026-03-15',
                    '2026-03-15',
                    'Metformin ER 500 mg',
                    'Take 1 tablet by mouth daily with evening meal',
                ),
                new EvidenceBundleItem(
                    'medication',
                    'medication:prescriptions/af-rx-lisinopril@2026-03-15',
                    '2026-03-15',
                    'Lisinopril 10 mg',
                    'Take 1 tablet by mouth daily',
                ),
            ]),
        );

        $this->assertTrue($result->passed);
        $this->assertSame(['s1'], $result->verifiedSentenceIds);
    }

    public function testMislabeledAllergyClaimWithoutCitationIsBlocked(): void
    {
        $result = (new DraftVerifier())->verify(
            new DraftResponse(
                [new DraftSentence('s1', 'Penicillin allergy: reaction: rash')],
                [new DraftClaim('Penicillin allergy: reaction: rash', DraftClaim::TYPE_WARNING, [], 's1')],
                [],
                [],
                DraftUsage::fixture(),
            ),
            new EvidenceBundle([
                new EvidenceBundleItem(
                    'allergy',
                    'allergy:lists/af-al-penicillin@2026-04-01',
                    '2026-04-01',
                    'Penicillin',
                    'reaction: rash',
                ),
            ]),
        );

        $this->assertFalse($result->passed);
    }

    public function testGroundedAllergyListMayUseNonFactualLeadIn(): void
    {
        $result = (new DraftVerifier())->verify(
            new DraftResponse(
                [new DraftSentence('s1', 'Active allergies include Penicillin: reaction: rash and Shellfish: reaction: hives.')],
                [
                    new DraftClaim(
                        'Penicillin: reaction: rash',
                        DraftClaim::TYPE_PATIENT_FACT,
                        ['allergy:lists/af-al-penicillin@2026-04-01'],
                        's1',
                    ),
                    new DraftClaim(
                        'Shellfish: reaction: hives',
                        DraftClaim::TYPE_PATIENT_FACT,
                        ['allergy:lists/af-al-shellfish@2025-11-20'],
                        's1',
                    ),
                ],
                [],
                [],
                DraftUsage::fixture(),
            ),
            new EvidenceBundle([
                new EvidenceBundleItem(
                    'allergy',
                    'allergy:lists/af-al-penicillin@2026-04-01',
                    '2026-04-01',
                    'Penicillin',
                    'reaction: rash',
                ),
                new EvidenceBundleItem(
                    'allergy',
                    'allergy:lists/af-al-shellfish@2025-11-20',
                    '2025-11-20',
                    'Shellfish',
                    'reaction: hives',
                ),
            ]),
        );

        $this->assertTrue($result->passed);
        $this->assertSame(['s1'], $result->verifiedSentenceIds);
    }

    public function testGroundedAllergySentenceMayUseAllergyContextNoun(): void
    {
        $result = (new DraftVerifier())->verify(
            new DraftResponse(
                [new DraftSentence('s1', 'Penicillin allergy: reaction: rash.')],
                [
                    new DraftClaim(
                        'Penicillin: reaction: rash',
                        DraftClaim::TYPE_PATIENT_FACT,
                        ['allergy:lists/af-al-penicillin@2026-04-01'],
                        's1',
                    ),
                ],
                [],
                [],
                DraftUsage::fixture(),
            ),
            new EvidenceBundle([
                new EvidenceBundleItem(
                    'allergy',
                    'allergy:lists/af-al-penicillin@2026-04-01',
                    '2026-04-01',
                    'Penicillin',
                    'reaction: rash',
                ),
            ]),
        );

        $this->assertTrue($result->passed);
        $this->assertSame(['s1'], $result->verifiedSentenceIds);
    }

    public function testMislabeledVitalClaimWithoutCitationIsBlocked(): void
    {
        $result = (new DraftVerifier())->verify(
            new DraftResponse(
                [new DraftSentence('s1', 'Blood pressure: 142/88 mmHg')],
                [new DraftClaim('Blood pressure: 142/88 mmHg', DraftClaim::TYPE_WARNING, [], 's1')],
                [],
                [],
                DraftUsage::fixture(),
            ),
            new EvidenceBundle([
                new EvidenceBundleItem(
                    'vital',
                    'vital:form_vitals/af-vitals-20260415-blood-pressure@2026-04-15',
                    '2026-04-15',
                    'Blood pressure',
                    '142/88 mmHg',
                ),
            ]),
        );

        $this->assertFalse($result->passed);
    }

    public function testGroundedVitalsListMayUseNonFactualLeadIn(): void
    {
        $result = (new DraftVerifier())->verify(
            new DraftResponse(
                [new DraftSentence('s1', 'Recent vitals show Blood pressure: 142/88 mmHg and Pulse: 84 bpm.')],
                [
                    new DraftClaim(
                        'Blood pressure: 142/88 mmHg',
                        DraftClaim::TYPE_PATIENT_FACT,
                        ['vital:form_vitals/af-vitals-20260415-blood-pressure@2026-04-15'],
                        's1',
                    ),
                    new DraftClaim(
                        'Pulse: 84 bpm',
                        DraftClaim::TYPE_PATIENT_FACT,
                        ['vital:form_vitals/af-vitals-20260415-pulse@2026-04-15'],
                        's1',
                    ),
                ],
                [],
                [],
                DraftUsage::fixture(),
            ),
            new EvidenceBundle([
                new EvidenceBundleItem(
                    'vital',
                    'vital:form_vitals/af-vitals-20260415-blood-pressure@2026-04-15',
                    '2026-04-15',
                    'Blood pressure',
                    '142/88 mmHg',
                ),
                new EvidenceBundleItem(
                    'vital',
                    'vital:form_vitals/af-vitals-20260415-pulse@2026-04-15',
                    '2026-04-15',
                    'Pulse',
                    '84 bpm',
                ),
            ]),
        );

        $this->assertTrue($result->passed);
        $this->assertSame(['s1'], $result->verifiedSentenceIds);
    }

    public function testClinicalAdviceClaimIsRefusedEvenWithEvidenceSource(): void
    {
        $result = (new DraftVerifier())->verify(
            $this->draft('Hemoglobin A1c: 7.4 %. Increase metformin dose.', ['lab:procedure_result/a1c@2026-04-10']),
            $this->bundle(),
        );

        $this->assertFalse($result->passed);
        $this->assertStringContainsString('cannot provide diagnosis', implode(' ', $result->refusalsOrWarnings));
    }

    public function testUnsafeSentenceTextIsRejectedEvenWhenClaimTextLooksSupported(): void
    {
        $result = (new DraftVerifier())->verify(
            new DraftResponse(
                [new DraftSentence('s1', 'Hemoglobin A1c: 7.4 %. Increase metformin dose.')],
                [
                    new DraftClaim(
                        'Hemoglobin A1c: 7.4 %',
                        DraftClaim::TYPE_PATIENT_FACT,
                        ['lab:procedure_result/a1c@2026-04-10'],
                        's1',
                    ),
                ],
                [],
                [],
                DraftUsage::fixture(),
            ),
            $this->bundle(),
        );

        $this->assertFalse($result->passed);
        $this->assertSame([], $result->verifiedSentenceIds);
    }

    /** @param list<string> $sourceIds */
    private function draft(string $claimText, array $sourceIds): DraftResponse
    {
        return new DraftResponse(
            [new DraftSentence('s1', $claimText)],
            [new DraftClaim($claimText, DraftClaim::TYPE_PATIENT_FACT, $sourceIds, 's1')],
            [],
            [],
            DraftUsage::fixture(),
        );
    }

    private function bundle(): EvidenceBundle
    {
        return new EvidenceBundle([
            new EvidenceBundleItem(
                'lab',
                'lab:procedure_result/a1c@2026-04-10',
                '2026-04-10',
                'Hemoglobin A1c',
                '7.4 %',
            ),
        ]);
    }
}
