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
use OpenEMR\AgentForge\ResponseGeneration\FixtureDraftProvider;
use OpenEMR\AgentForge\Verification\DraftVerifier;
use PHPUnit\Framework\TestCase;

final class VerifiedDraftingPipelineTest extends TestCase
{
    public function testKnownMissingDataIsAppliedOutsideProviderSpecificBehavior(): void
    {
        $pipeline = new VerifiedDraftingPipeline(
            new PipelineMissingAwareDraftProvider(),
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
    }

    public function testProviderFailureAfterEvidencePreservesUsefulTelemetry(): void
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

        $this->assertSame('refused', $result->response->status);
        $this->assertSame('draft_provider_unavailable', $result->telemetry->failureReason);
        $this->assertSame(['Recent labs'], $result->telemetry->toolsCalled);
        $this->assertSame(['lab:procedure_result/a1c@2026-04-10'], $result->telemetry->sourceIds);
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
}

final readonly class PipelineMissingAwareDraftProvider implements DraftProvider
{
    public function draft(AgentRequest $request, EvidenceBundle $bundle, Deadline $deadline): DraftResponse
    {
        return (new FixtureDraftProvider())->draft($request, $bundle, $deadline);
    }
}

final readonly class PipelineUnavailableProvider implements DraftProvider
{
    public function draft(AgentRequest $request, EvidenceBundle $bundle, Deadline $deadline): DraftResponse
    {
        throw new DraftProviderException('cURL timeout internals');
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
