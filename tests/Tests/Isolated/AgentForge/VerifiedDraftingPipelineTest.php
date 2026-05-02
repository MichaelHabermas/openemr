<?php

/**
 * Isolated tests for the AgentForge verified drafting boundary.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Tests\Isolated\AgentForge;

use OpenEMR\AgentForge\Auth\PatientId;
use OpenEMR\AgentForge\Deadline;
use OpenEMR\AgentForge\Evidence\EvidenceBundle;
use OpenEMR\AgentForge\Evidence\EvidenceBundleItem;
use OpenEMR\AgentForge\Handlers\AgentQuestion;
use OpenEMR\AgentForge\Handlers\AgentRequest;
use OpenEMR\AgentForge\Handlers\VerifiedDraftingPipeline;
use OpenEMR\AgentForge\ResponseGeneration\DraftClaim;
use OpenEMR\AgentForge\ResponseGeneration\DraftProvider;
use OpenEMR\AgentForge\ResponseGeneration\DraftProviderException;
use OpenEMR\AgentForge\ResponseGeneration\DraftResponse;
use OpenEMR\AgentForge\ResponseGeneration\DraftSentence;
use OpenEMR\AgentForge\ResponseGeneration\DraftUsage;
use OpenEMR\AgentForge\Verification\DraftVerifier;
use PHPUnit\Framework\TestCase;

final class VerifiedDraftingPipelineTest extends TestCase
{
    public function testKnownMissingDataIsAppliedOutsideProviderSpecificBehavior(): void
    {
        $pipeline = new VerifiedDraftingPipeline(
            new PipelineProviderThatMustNotBeCalled(),
            new DraftVerifier(),
        );

        $result = $pipeline->run(
            new AgentRequest(
                new PatientId(900001),
                new AgentQuestion('Has Alex had a urine microalbumin result in the chart?'),
            ),
            $this->bundle(),
            'lab',
            ['Recent labs'],
        );

        $this->assertSame('ok', $result->response->status);
        $this->assertContains('Urine microalbumin not found in the chart.', $result->response->missingOrUncheckedSections);
        $this->assertStringContainsString('Urine microalbumin not found in the chart.', $result->response->answer);
        $this->assertSame([], $result->response->citations);
        $this->assertSame('not_run', $result->telemetry->model);
        $this->assertNull($result->telemetry->failureReason);
        $this->assertSame('passed', $result->telemetry->verifierResult);
        $this->assertSame(['lab:procedure_result/a1c@2026-04-10'], $result->telemetry->sourceIds);
    }

    public function testProviderFailureAfterEvidenceFallsBackToVerifiedEvidence(): void
    {
        $result = (new VerifiedDraftingPipeline(
            new PipelineUnavailableProvider(),
            new DraftVerifier(),
        ))->run(
            new AgentRequest(new PatientId(900001), new AgentQuestion('Show me recent labs.')),
            $this->bundle(),
            'lab',
            ['Recent labs'],
        );

        $this->assertSame('ok', $result->response->status);
        $this->assertStringContainsString('Hemoglobin A1c: 7.4 %', $result->response->answer);
        $this->assertSame(['lab:procedure_result/a1c@2026-04-10'], $result->response->citations);
        $this->assertSame(
            ['The model draft provider could not be reached; deterministic evidence fallback was used.'],
            $result->response->refusalsOrWarnings,
        );
        $this->assertSame('draft_provider_unavailable_fallback_used', $result->telemetry->failureReason);
        $this->assertSame('fallback_passed', $result->telemetry->verifierResult);
        $this->assertSame(['Recent labs'], $result->telemetry->toolsCalled);
        $this->assertSame(['lab:procedure_result/a1c@2026-04-10'], $result->telemetry->sourceIds);
    }

    public function testProviderFailureVisitBriefingFallbackDoesNotDuplicateMedicationLines(): void
    {
        $result = (new VerifiedDraftingPipeline(
            new PipelineUnavailableProvider(),
            new DraftVerifier(),
        ))->run(
            new AgentRequest(new PatientId(900001), new AgentQuestion('Tell me about this patient.')),
            $this->briefingBundle(),
            'visit_briefing',
            ['Demographics', 'Active medications'],
        );

        $this->assertSame('ok', $result->response->status);
        $this->assertSame(1, substr_count($result->response->answer, 'Metformin ER 500 mg: 500 mg'));
        $this->assertSame(1, substr_count($result->response->answer, 'Lisinopril 10 mg: 10 mg'));
        $this->assertStringContainsString(
            'Metformin ER 500 mg: 500 mg [medication:prescriptions/af-rx-metformin@2026-03-15]',
            $result->response->answer,
        );
        $this->assertStringContainsString(
            'Lisinopril 10 mg: 10 mg [medication:prescriptions/af-rx-lisinopril@2026-03-15]',
            $result->response->answer,
        );
    }

    public function testVisitBriefingAppendsMedicationEvidenceWhenVerifiedDraftOmitsIt(): void
    {
        $result = (new VerifiedDraftingPipeline(
            new PipelineBriefingWithoutMedicationProvider(),
            new DraftVerifier(),
        ))->run(
            new AgentRequest(new PatientId(900001), new AgentQuestion('Give me a visit briefing.')),
            $this->briefingBundle(),
            'visit_briefing',
            ['Demographics', 'Active medications'],
        );

        $this->assertSame('ok', $result->response->status);
        $this->assertStringContainsString('Patient name: Alex Testpatient', $result->response->answer);
        $this->assertStringContainsString('Metformin ER 500 mg: 500 mg', $result->response->answer);
        $this->assertStringContainsString('Lisinopril 10 mg: 10 mg', $result->response->answer);
        $this->assertContains('medication:prescriptions/af-rx-metformin@2026-03-15', $result->response->citations);
        $this->assertContains('medication:prescriptions/af-rx-lisinopril@2026-03-15', $result->response->citations);
        $this->assertSame('passed', $result->telemetry->verifierResult);
    }

    public function testVisitBriefingDoesNotDuplicateMedicationEvidenceAlreadyPresent(): void
    {
        $result = (new VerifiedDraftingPipeline(
            new PipelineBriefingWithMedicationProvider(),
            new DraftVerifier(),
        ))->run(
            new AgentRequest(new PatientId(900001), new AgentQuestion('Give me a visit briefing.')),
            $this->briefingBundle(),
            'visit_briefing',
            ['Demographics', 'Active medications'],
        );

        $this->assertSame(1, substr_count($result->response->answer, 'Metformin ER 500 mg: 500 mg'));
        $this->assertSame(1, substr_count($result->response->answer, 'Lisinopril 10 mg: 10 mg'));
    }

    public function testMedicationAnswerDoesNotDuplicateAlreadyCitedMedicationEvidence(): void
    {
        $result = (new VerifiedDraftingPipeline(
            new PipelineBriefingWithMedicationProvider(),
            new DraftVerifier(),
        ))->run(
            new AgentRequest(new PatientId(900001), new AgentQuestion('What medications are active?')),
            $this->briefingBundle(),
            'medication',
            ['Active medications'],
        );

        $this->assertSame(1, substr_count($result->response->answer, 'Metformin ER 500 mg: 500 mg'));
        $this->assertSame(1, substr_count($result->response->answer, 'Lisinopril 10 mg: 10 mg'));
    }

    public function testMedicationAnswerAppendsMedicationEvidenceWhenVerifiedDraftOmitsIt(): void
    {
        $result = (new VerifiedDraftingPipeline(
            new PipelineBriefingWithoutMedicationProvider(),
            new DraftVerifier(),
        ))->run(
            new AgentRequest(new PatientId(900001), new AgentQuestion('What medications are active?')),
            $this->briefingBundle(),
            'medication',
            ['Active medications'],
        );

        $this->assertStringContainsString('Metformin ER 500 mg: 500 mg', $result->response->answer);
        $this->assertStringContainsString('Lisinopril 10 mg: 10 mg', $result->response->answer);
        $this->assertContains('medication:prescriptions/af-rx-metformin@2026-03-15', $result->response->citations);
        $this->assertContains('medication:prescriptions/af-rx-lisinopril@2026-03-15', $result->response->citations);
    }

    public function testNonBriefingNonMedicationAnswerDoesNotAppendMedicationEvidence(): void
    {
        $result = (new VerifiedDraftingPipeline(
            new PipelineBriefingWithoutMedicationProvider(),
            new DraftVerifier(),
        ))->run(
            new AgentRequest(new PatientId(900001), new AgentQuestion('Show me patient demographics.')),
            $this->briefingBundle(),
            'demographics',
            ['Demographics', 'Active medications'],
        );

        $this->assertStringNotContainsString('Metformin ER 500 mg: 500 mg', $result->response->answer);
        $this->assertStringNotContainsString('Lisinopril 10 mg: 10 mg', $result->response->answer);
    }

    public function testUnsupportedClaimIsBlockedAtPipelineBoundary(): void
    {
        $result = (new VerifiedDraftingPipeline(
            new PipelineFabricatingProvider(),
            new DraftVerifier(),
        ))->run(
            new AgentRequest(new PatientId(900001), new AgentQuestion('Show me recent labs.')),
            $this->bundle(),
            'lab',
            ['Recent labs'],
        );

        $this->assertSame('refused', $result->response->status);
        $this->assertSame('verification_failed', $result->telemetry->failureReason);
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

    private function briefingBundle(): EvidenceBundle
    {
        return new EvidenceBundle([
            new EvidenceBundleItem(
                'demographic',
                'demographic:patient_data/900001-name@2026-04-15',
                '2026-04-15',
                'Patient name',
                'Alex Testpatient',
            ),
            new EvidenceBundleItem(
                'medication',
                'medication:prescriptions/af-rx-metformin@2026-03-15',
                '2026-03-15',
                'Metformin ER 500 mg',
                '500 mg',
            ),
            new EvidenceBundleItem(
                'medication',
                'medication:prescriptions/af-rx-lisinopril@2026-03-15',
                '2026-03-15',
                'Lisinopril 10 mg',
                '10 mg',
            ),
        ]);
    }
}

final readonly class PipelineProviderThatMustNotBeCalled implements DraftProvider
{
    public function draft(AgentRequest $request, EvidenceBundle $bundle, Deadline $deadline): DraftResponse
    {
        throw new DraftProviderException('Known missing data should not call the draft provider.');
    }
}

final readonly class PipelineUnavailableProvider implements DraftProvider
{
    public function draft(AgentRequest $request, EvidenceBundle $bundle, Deadline $deadline): DraftResponse
    {
        throw new DraftProviderException('cURL timeout internals');
    }
}

final readonly class PipelineBriefingWithoutMedicationProvider implements DraftProvider
{
    public function draft(AgentRequest $request, EvidenceBundle $bundle, Deadline $deadline): DraftResponse
    {
        return new DraftResponse(
            [new DraftSentence('s1', 'Patient name: Alex Testpatient')],
            [
                new DraftClaim(
                    'Patient name: Alex Testpatient',
                    DraftClaim::TYPE_PATIENT_FACT,
                    ['demographic:patient_data/900001-name@2026-04-15'],
                    's1',
                ),
            ],
            [],
            [],
            DraftUsage::fixture(),
        );
    }
}

final readonly class PipelineBriefingWithMedicationProvider implements DraftProvider
{
    public function draft(AgentRequest $request, EvidenceBundle $bundle, Deadline $deadline): DraftResponse
    {
        return new DraftResponse(
            [
                new DraftSentence('s1', 'Patient name: Alex Testpatient'),
                new DraftSentence('s2', 'Metformin ER 500 mg: 500 mg'),
                new DraftSentence('s3', 'Lisinopril 10 mg: 10 mg'),
            ],
            [
                new DraftClaim(
                    'Patient name: Alex Testpatient',
                    DraftClaim::TYPE_PATIENT_FACT,
                    ['demographic:patient_data/900001-name@2026-04-15'],
                    's1',
                ),
                new DraftClaim(
                    'Metformin ER 500 mg: 500 mg',
                    DraftClaim::TYPE_PATIENT_FACT,
                    ['medication:prescriptions/af-rx-metformin@2026-03-15'],
                    's2',
                ),
                new DraftClaim(
                    'Lisinopril 10 mg: 10 mg',
                    DraftClaim::TYPE_PATIENT_FACT,
                    ['medication:prescriptions/af-rx-lisinopril@2026-03-15'],
                    's3',
                ),
            ],
            [],
            [],
            DraftUsage::fixture(),
        );
    }
}

final readonly class PipelineFabricatingProvider implements DraftProvider
{
    public function draft(AgentRequest $request, EvidenceBundle $bundle, Deadline $deadline): DraftResponse
    {
        return new DraftResponse(
            [new DraftSentence('s1', 'Hemoglobin A1c: 11.9 %')],
            [new DraftClaim('Hemoglobin A1c: 11.9 %', DraftClaim::TYPE_PATIENT_FACT, ['lab:procedure_result/a1c@2026-04-10'], 's1')],
            [],
            [],
            DraftUsage::fixture(),
        );
    }
}
