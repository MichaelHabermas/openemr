<?php

/**
 * Collects selected chart evidence with sanitized failure and deadline behavior.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\AgentForge\Evidence;

use OpenEMR\AgentForge\AgentForgeClock;
use OpenEMR\AgentForge\Auth\PatientId;
use OpenEMR\AgentForge\Deadline;
use OpenEMR\AgentForge\Observability\StageTimer;
use OpenEMR\AgentForge\SystemAgentForgeClock;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

final class ChartEvidenceCollector
{
    /** @var array<string, ChartEvidenceTool> */
    private array $toolsBySection = [];

    private readonly AgentForgeClock $clock;

    /** @param list<ChartEvidenceTool> $tools */
    public function __construct(
        array $tools,
        private readonly LoggerInterface $logger = new NullLogger(),
        ?AgentForgeClock $clock = null,
    ) {
        foreach ($tools as $tool) {
            $this->toolsBySection[$tool->section()] ??= $tool;
        }
        $this->clock = $clock ?? new SystemAgentForgeClock();
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
