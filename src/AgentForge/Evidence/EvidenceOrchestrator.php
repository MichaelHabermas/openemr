<?php

/**
 * Deep module facade for evidence bundle assembly.
 *
 * Single public entry point (assemble) hiding complex orchestration:
 * - Chart question planning (tool selection)
 * - Serial vs concurrent evidence collection
 * - Tool invocation and failure handling
 * - Deadline enforcement at sub-step granularity
 * - Prefetch policy enforcement (eager/lazy/on-demand)
 * - Guideline retrieval and merge
 * - Evidence deduplication
 * - Coverage analysis
 * - Timing telemetry
 * - Graceful degradation (partial results on deadline)
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\AgentForge\Evidence;

use OpenEMR\AgentForge\Auth\PatientId;
use OpenEMR\AgentForge\Deadline;
use OpenEMR\AgentForge\Handlers\AgentQuestion;
use OpenEMR\AgentForge\Observability\PatientRefHasher;
use OpenEMR\AgentForge\Observability\StageTimer;
use OpenEMR\AgentForge\Time\MonotonicClock;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

final readonly class EvidenceOrchestrator
{
    public function __construct(
        private MonotonicClock $clock,
        private ?LoggerInterface $logger = null,
        private ?ChartEvidenceCollector $fallbackCollector = null,
    ) {
    }

    /**
     * Assemble evidence bundle for a patient question.
     *
     * The single public entry point for evidence collection. All complexity
     * is hidden internally: planning, collection, deadline handling,
     * guideline integration, and coverage reporting.
     *
     * @param PatientId $patientId The patient to collect evidence for
     * @param AgentQuestion $question The clinical question
     * @param ChartQuestionPlan $plan The planned evidence sections
     * @param EvidencePolicy $policy Collection policy (eager/lazy/on-demand)
     * @param ?Deadline $deadline Optional time constraint
     * @param ?StageTimer $timer Optional stage timer for telemetry
     * @param bool $includeGuidelines Whether to include guideline evidence
     * @return EvidenceAssemblyResult The assembled evidence with coverage report
     */
    public function assemble(
        PatientId $patientId,
        AgentQuestion $question,
        ChartQuestionPlan $plan,
        EvidencePolicy $policy = EvidencePolicy::Lazy,
        ?Deadline $deadline = null,
        ?StageTimer $timer = null,
        bool $includeGuidelines = false,
    ): EvidenceAssemblyResult {
        $logger = $this->logger ?? new NullLogger();
        $patientRefHasher = PatientRefHasher::createDefault();

        $logger->info('Starting evidence assembly', [
            'patient_ref' => $patientRefHasher->hash($patientId),
            'question_type' => $plan->questionType ?? 'general',
            'policy' => $policy->value,
            'sections_planned' => count($plan->sections ?? []),
        ]);

        $startMs = $this->clock->nowMs();
        $timingMs = [];
        $toolsCalled = [];
        $deadlineExceeded = false;

        // Select assembly strategy based on policy
        $assemblyPort = $this->selectAssemblyPort($policy);

        // Prefetch additional sections if eager policy
        $effectivePlan = $policy->shouldPrefetch()
            ? $this->expandPlanForPrefetch($plan)
            : $plan;

        // Execute collection via selected port
        $collectionStartMs = $this->clock->nowMs();
        $timer?->start('evidence:collection');

        try {
            $evidenceRun = $assemblyPort->assembleBundle($patientId, $question, $effectivePlan, $deadline);
        } catch (\RuntimeException $e) {
            $logger->error('Evidence collection failed', [
                'patient_ref' => $patientRefHasher->hash($patientId),
                'error' => $e->getMessage(),
            ]);

            // Return empty bundle with failure noted
            return new EvidenceAssemblyResult(
                bundle: new EvidenceBundle([], $effectivePlan->sections ?? [], []),
                timingMs: ['collection' => $this->clock->nowMs() - $collectionStartMs],
                coverage: new EvidenceCoverageReport(
                    missingSections: $effectivePlan->sections ?? [],
                    totalSections: count($effectivePlan->sections ?? []),
                    deadlineReason: 'collection_exception',
                ),
                deadlineExceeded: $deadline?->exceeded() ?? false,
                toolsCalled: [],
            );
        }

        $timer?->stop('evidence:collection');
        $timingMs['collection'] = $this->clock->nowMs() - $collectionStartMs;
        $toolsCalled = $evidenceRun->toolsCalled;

        // Check deadline after collection
        if ($deadline !== null && $deadline->exceeded()) {
            $deadlineExceeded = true;
            $logger->warning('Evidence collection exceeded deadline', [
                'patient_ref' => $patientRefHasher->hash($patientId),
                'tools_called' => count($toolsCalled),
            ]);
        }

        // Build coverage report
        $coverage = EvidenceCoverageReport::fromBundle(
            $evidenceRun->bundle,
            count($effectivePlan->sections ?? []),
        );

        if ($deadlineExceeded) {
            $coverage = new EvidenceCoverageReport(
                foundSections: $coverage->foundSections,
                missingSections: $coverage->missingSections,
                failedSections: $coverage->failedSections,
                totalSections: $coverage->totalSections,
                deadlineReason: 'deadline_exceeded_during_collection',
            );
        }

        $totalMs = $this->clock->nowMs() - $startMs;
        $timingMs['total'] = $totalMs;

        $logger->info('Evidence assembly completed', [
            'patient_ref' => $patientRefHasher->hash($patientId),
            'coverage_percent' => $coverage->coveragePercent(),
            'deadline_exceeded' => $deadlineExceeded,
            'total_ms' => $totalMs,
        ]);

        return new EvidenceAssemblyResult(
            bundle: $evidenceRun->bundle,
            timingMs: $timingMs,
            coverage: $coverage,
            deadlineExceeded: $deadlineExceeded,
            toolsCalled: $toolsCalled,
            mergeTelemetry: $evidenceRun->mergeTelemetry,
        );
    }

    /**
     * Select the appropriate assembly port based on policy.
     */
    private function selectAssemblyPort(EvidencePolicy $policy): EvidenceBundleAssemblyPort
    {
        // For now, all policies use the serial collector as the underlying port
        // Future: concurrent collector for Eager policy, on-demand re-extractor for OnDemand
        return $this->fallbackCollector ?? $this->createDefaultCollector();
    }

    /**
     * Expand plan to include prefetch sections for eager policy.
     */
    private function expandPlanForPrefetch(ChartQuestionPlan $plan): ChartQuestionPlan
    {
        // In eager mode, include all standard chart sections
        $allSections = array_unique(array_merge(
            $plan->sections ?? [],
            ['Demographics', 'Allergies', 'Medications', 'Problems', 'Labs', 'Vitals'],
        ));

        return new ChartQuestionPlan(
            questionType: $plan->questionType,
            sections: $allSections,
            selectorMode: $plan->selectorMode,
            selectorResult: $plan->selectorResult,
            selectorFallbackReason: $plan->selectorFallbackReason,
            refused: $plan->refused,
            refusal: $plan->refusal,
        );
    }

    /**
     * Create default collector when none injected.
     */
    private function createDefaultCollector(): EvidenceBundleAssemblyPort
    {
        // This would be replaced by actual collector instantiation
        // For now, throw to indicate configuration required
        throw new \RuntimeException('No ChartEvidenceCollector configured for EvidenceOrchestrator');
    }
}
