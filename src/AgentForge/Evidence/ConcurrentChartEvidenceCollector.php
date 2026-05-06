<?php

/**
 * Coalesced chart-evidence collector. Issues one repository prefetch for the
 * planner's section set before invoking per-section tools, so a batching
 * repository (UNION ALL / multi-query / future async) can serve all reads
 * from a single coordinated round-trip.
 *
 * When the injected repository does not implement
 * {@see PrefetchableChartEvidenceRepository} this collector behaves exactly
 * like {@see SerialChartEvidenceCollector} — the prefetch is a no-op and per-
 * section calls fall through to the underlying repository as usual.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\AgentForge\Evidence;

use OpenEMR\AgentForge\Auth\PatientId;
use OpenEMR\AgentForge\Deadline;
use OpenEMR\AgentForge\Observability\StageTimer;
use OpenEMR\AgentForge\Time\MonotonicClock;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

final class ConcurrentChartEvidenceCollector implements ChartEvidenceCollector
{
    /** @var array<string, ChartEvidenceTool> */
    private array $toolsBySection = [];

    /** @param list<ChartEvidenceTool> $tools */
    public function __construct(
        array $tools,
        private readonly ?PrefetchableChartEvidenceRepository $prefetcher,
        private readonly LoggerInterface $logger,
        private readonly MonotonicClock $clock,
    ) {
        foreach ($tools as $tool) {
            $this->toolsBySection[$tool->section()] ??= $tool;
        }
    }

    public function collect(
        PatientId $patientId,
        ChartQuestionPlan $plan,
        ?StageTimer $timer = null,
        ?Deadline $deadline = null,
    ): EvidenceRun {
        $deadline ??= new Deadline($this->clock, $plan->deadlineMs);
        $results = [];
        $toolsCalled = [];

        if ($this->prefetcher !== null && $plan->sections !== []) {
            $timer?->start('evidence:prefetch');
            $this->prefetcher->prefetch($patientId, $plan->sections, $deadline);
            $timer?->stop('evidence:prefetch');
        }

        foreach ($plan->sections as $section) {
            $tool = $this->toolsBySection[$section] ?? null;
            if ($tool === null) {
                $results[] = EvidenceResult::failure($section, sprintf('%s could not be checked.', $section));
                continue;
            }

            $stageKey = 'evidence:' . $tool->section();
            $timer?->start($stageKey);
            $toolsCalled[] = $tool->section();
            $results[] = ChartEvidenceToolInvoker::collectOrFailure($tool, $patientId, $this->logger, $deadline);
            $timer?->stop($stageKey);

            if ($deadline->exceeded()) {
                $results[] = EvidenceResult::failure(
                    'Deadline',
                    'Some chart sections could not be checked before the deadline.',
                );
                break;
            }
        }

        return new EvidenceRun(
            EvidenceBundle::fromEvidenceResults($results),
            $results,
            array_values(array_unique($toolsCalled)),
            $plan->skippedSections,
        );
    }
}
