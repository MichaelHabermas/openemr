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

use DomainException;
use OpenEMR\AgentForge\AgentForgeClock;
use OpenEMR\AgentForge\Auth\PatientId;
use OpenEMR\AgentForge\SystemAgentForgeClock;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use RuntimeException;

final class ChartEvidenceCollector
{
    /** @var array<string, ChartEvidenceTool> */
    private array $toolsBySection = [];

    private AgentForgeClock $clock;

    /** @param list<ChartEvidenceTool> $tools */
    public function __construct(
        array $tools,
        private LoggerInterface $logger = new NullLogger(),
        ?AgentForgeClock $clock = null,
    ) {
        foreach ($tools as $tool) {
            $this->toolsBySection[$tool->section()] ??= $tool;
        }
        $this->clock = $clock ?? new SystemAgentForgeClock();
    }

    public function collect(PatientId $patientId, ChartQuestionPlan $plan): EvidenceRun
    {
        $startMs = $this->clock->nowMs();
        $results = [];
        $toolsCalled = [];

        foreach ($plan->sections as $section) {
            $tool = $this->toolsBySection[$section] ?? null;
            if ($tool === null) {
                $results[] = EvidenceResult::failure($section, sprintf('%s could not be checked.', $section));
                continue;
            }

            $toolsCalled[] = $tool->section();
            try {
                $results[] = $tool->collect($patientId);
            } catch (DomainException | RuntimeException $exception) {
                $this->logger->error(
                    'AgentForge evidence tool failed unexpectedly.',
                    [
                        'failure_class' => $exception::class,
                        'tool' => $tool::class,
                        'patient_id' => $patientId->value,
                    ],
                );
                $results[] = EvidenceResult::failure(
                    $tool->section(),
                    sprintf('%s could not be checked.', $tool->section()),
                );
            }

            if ($this->deadlineExceeded($startMs, $plan->deadlineMs)) {
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
        );
    }

    private function deadlineExceeded(int $startMs, int $deadlineMs): bool
    {
        return $deadlineMs >= 0 && ($this->clock->nowMs() - $startMs) > $deadlineMs;
    }
}
