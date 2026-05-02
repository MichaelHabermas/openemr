<?php

/**
 * Agent handler that drafts from evidence and returns only verified output.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\AgentForge\Handlers;

use OpenEMR\AgentForge\AgentForgeClock;
use OpenEMR\AgentForge\AgentTelemetry;
use OpenEMR\AgentForge\AgentTelemetryProvider;
use OpenEMR\AgentForge\Deadline;
use OpenEMR\AgentForge\Evidence\ChartEvidenceCollector;
use OpenEMR\AgentForge\Evidence\ChartEvidenceTool;
use OpenEMR\AgentForge\Evidence\ChartQuestionPlanner;
use OpenEMR\AgentForge\ResponseGeneration\DraftProvider;
use OpenEMR\AgentForge\StageTimer;
use OpenEMR\AgentForge\SystemAgentForgeClock;
use OpenEMR\AgentForge\Verification\DraftVerifier;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

final class VerifiedAgentHandler implements AgentHandler, AgentTelemetryProvider
{
    private ?AgentTelemetry $lastTelemetry = null;
    private readonly ChartQuestionPlanner $planner;
    private readonly ChartEvidenceCollector $collector;
    private readonly VerifiedDraftingPipeline $pipeline;
    private readonly AgentForgeClock $clock;

    /**
     * @param list<ChartEvidenceTool> $tools
     */
    public function __construct(
        array $tools,
        DraftProvider $draftProvider,
        DraftVerifier $verifier,
        LoggerInterface $logger = new NullLogger(),
        ?AgentForgeClock $clock = null,
        private readonly int $deadlineMs = 20000,
    ) {
        $this->clock = $clock ?? new SystemAgentForgeClock();
        $this->planner = new ChartQuestionPlanner();
        $this->collector = new ChartEvidenceCollector($tools, $logger, $this->clock);
        $this->pipeline = new VerifiedDraftingPipeline($draftProvider, $verifier, $logger);
    }

    public function handle(AgentRequest $request): AgentResponse
    {
        $this->lastTelemetry = null;
        $plan = $this->planner->plan($request->question, $this->deadlineMs);
        if ($plan->refused()) {
            $this->lastTelemetry = AgentTelemetry::plannedRefusal(
                $plan->questionType,
                $plan->questionType,
                $plan->skippedSections,
            );
            return AgentResponse::refusal((string) $plan->refusal);
        }

        $deadline = new Deadline($this->clock, $this->deadlineMs);
        $timer = new StageTimer($this->clock);
        $evidenceRun = $this->collector->collect($request->patientId, $plan, $timer, $deadline);
        $draftingResult = $this->pipeline->run(
            $request,
            $evidenceRun->bundle,
            $plan->questionType,
            $evidenceRun->toolsCalled,
            $evidenceRun->skippedSections,
            $timer,
            $deadline,
        );
        $this->lastTelemetry = $draftingResult->telemetry->withStageTimings($timer->timings());

        return $draftingResult->response;
    }

    public function lastTelemetry(): ?AgentTelemetry
    {
        return $this->lastTelemetry;
    }
}
