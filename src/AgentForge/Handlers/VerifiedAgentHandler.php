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

use OpenEMR\AgentForge\Deadline;
use OpenEMR\AgentForge\Evidence\ChartEvidenceCollector;
use OpenEMR\AgentForge\Evidence\ChartEvidenceTool;
use OpenEMR\AgentForge\Evidence\ChartQuestionPlanner;
use OpenEMR\AgentForge\Evidence\EvidenceRetrieverWorker;
use OpenEMR\AgentForge\Evidence\SerialChartEvidenceCollector;
use OpenEMR\AgentForge\Evidence\ToolSelectionProvider;
use OpenEMR\AgentForge\Guidelines\GuidelineRetriever;
use OpenEMR\AgentForge\Observability\AgentTelemetry;
use OpenEMR\AgentForge\Observability\AgentTelemetryProvider;
use OpenEMR\AgentForge\Observability\StageTimer;
use OpenEMR\AgentForge\Orchestration\NodeName;
use OpenEMR\AgentForge\Orchestration\SqlSupervisorHandoffRepository;
use OpenEMR\AgentForge\Orchestration\Supervisor;
use OpenEMR\AgentForge\Orchestration\SupervisorRuntime;
use OpenEMR\AgentForge\ResponseGeneration\DraftProvider;
use OpenEMR\AgentForge\Time\MonotonicClock;
use OpenEMR\AgentForge\Verification\CurrentChartScopePolicy;
use OpenEMR\AgentForge\Verification\DraftVerifier;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use RuntimeException;

final class VerifiedAgentHandler implements AgentHandler, AgentTelemetryProvider
{
    private ?AgentTelemetry $lastTelemetry = null;
    private readonly ChartQuestionPlanner $planner;
    private readonly ChartEvidenceCollector $collector;
    private readonly EvidenceRetrieverWorker $evidenceRetriever;
    private readonly VerifiedDraftingPipeline $pipeline;
    private readonly MonotonicClock $clock;
    private readonly LoggerInterface $logger;
    private readonly ?SupervisorRuntime $supervisorRuntime;

    /**
     * @param list<ChartEvidenceTool> $tools
     */
    public function __construct(
        array $tools,
        DraftProvider $draftProvider,
        DraftVerifier $verifier,
        MonotonicClock $clock,
        LoggerInterface $logger = new NullLogger(),
        private readonly int $deadlineMs = 20000,
        ?ChartEvidenceCollector $collector = null,
        ?ToolSelectionProvider $toolSelectionProvider = null,
        ?GuidelineRetriever $guidelineRetriever = null,
        ?SqlSupervisorHandoffRepository $handoffs = null,
        ?SupervisorRuntime $supervisorRuntime = null,
    ) {
        $this->clock = $clock;
        $this->logger = $logger;
        $this->planner = new ChartQuestionPlanner($toolSelectionProvider, $logger);
        $this->collector = $collector ?? new SerialChartEvidenceCollector($tools, $logger, $this->clock);
        $this->evidenceRetriever = new EvidenceRetrieverWorker($this->collector, $guidelineRetriever);
        $this->pipeline = new VerifiedDraftingPipeline($draftProvider, $verifier, $this->clock, $logger);
        $this->supervisorRuntime = $supervisorRuntime ?? ($handoffs === null ? null : new SupervisorRuntime(new Supervisor(), $handoffs));
    }

    public function handle(AgentRequest $request): AgentResponse
    {
        $this->lastTelemetry = null;
        $plannerTimer = new StageTimer($this->clock);
        $plannerTimer->start('planner');
        $scopeRefusal = CurrentChartScopePolicy::refusalFor($request->question, $request->patientId);
        if ($scopeRefusal !== null) {
            $plannerTimer->stop('planner');
            $this->lastTelemetry = AgentTelemetry::plannedRefusal(
                'cross_patient_refusal',
                'cross_patient_refusal',
                ChartQuestionPlanner::defaultSections(),
            )->withStageTimings($plannerTimer->timings());
            return AgentResponse::refusal($scopeRefusal);
        }

        $plan = $this->planner->plan($request->question, $this->deadlineMs, $request->conversationSummary);
        if ($plan->refused()) {
            $plannerTimer->stop('planner');
            $this->lastTelemetry = AgentTelemetry::plannedRefusal(
                $plan->questionType,
                $plan->questionType,
                $plan->skippedSections,
            )->withStageTimings($plannerTimer->timings());
            return AgentResponse::refusal((string) $plan->refusal);
        }
        $plannerTimer->stop('planner');

        $deadline = new Deadline($this->clock, $this->deadlineMs);
        $timer = new StageTimer($this->clock);
        $includeGuidelines = $this->requiresGuidelineEvidence($request->question->value, $plan->questionType);
        if ($includeGuidelines) {
            $this->recordGuidelineHandoff($request, $plan->questionType);
        }

        $evidenceRun = $this->evidenceRetriever->retrieve(
            $request->patientId,
            $request->question,
            $plan,
            $includeGuidelines,
            $timer,
            $deadline,
        );
        $draftingResult = $this->pipeline->run(
            $request,
            $evidenceRun->bundle,
            $plan->questionType,
            $evidenceRun->toolsCalled,
            $evidenceRun->skippedSections,
            $timer,
            $deadline,
        );
        $this->lastTelemetry = $draftingResult->telemetry
            ->withToolSelection($plan->selectorMode, $plan->selectorResult, $plan->selectorFallbackReason)
            ->withMergedStageTimings($plannerTimer->timings())
            ->withMergedStageTimings($timer->timings());

        return $draftingResult->response;
    }

    public function lastTelemetry(): ?AgentTelemetry
    {
        return $this->lastTelemetry;
    }

    private function requiresGuidelineEvidence(string $question, string $questionType): bool
    {
        $normalized = strtolower($question);
        if (
            str_contains($normalized, 'guideline')
            || str_contains($normalized, 'evidence')
            || str_contains($normalized, 'acc/aha')
            || str_contains($normalized, 'acc aha')
            || str_contains($normalized, 'ada')
            || str_contains($normalized, 'uspstf')
        ) {
            return true;
        }

        if (in_array($questionType, ['follow_up_change_review', 'pre_prescribing_chart_check'], true)) {
            return true;
        }

        return str_contains($normalized, 'what changed')
            || str_contains($normalized, 'deserves attention')
            || str_contains($normalized, 'pay attention');
    }

    private function recordGuidelineHandoff(AgentRequest $request, string $questionType): void
    {
        if ($this->supervisorRuntime === null || $request->requestId === null) {
            return;
        }

        try {
            $this->supervisorRuntime->recordRequestHandoff(
                $request->requestId,
                NodeName::EvidenceRetriever,
                'guideline_evidence_required',
                $questionType,
                'handoff',
            );
        } catch (RuntimeException $exception) {
            $this->logger->error('AgentForge supervisor handoff recording failed.', [
                'failure_class' => $exception::class,
                'request_id' => $request->requestId,
            ]);
        }
    }
}
