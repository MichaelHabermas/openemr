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

use OpenEMR\AgentForge\DraftClaim;
use OpenEMR\AgentForge\DraftResponse;
use OpenEMR\AgentForge\DraftSentence;
use OpenEMR\AgentForge\DraftUsage;
use OpenEMR\AgentForge\DraftVerifier;
use OpenEMR\AgentForge\EvidenceBundle;
use OpenEMR\AgentForge\EvidenceBundleItem;
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

    public function testSourceIdWithMismatchedValueIsBlocked(): void
    {
        $result = (new DraftVerifier())->verify(
            $this->draft('Hemoglobin A1c: 8.2 %', ['lab:procedure_result/a1c@2026-04-10']),
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
