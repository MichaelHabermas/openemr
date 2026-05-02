<?php

/**
 * Per-request stopwatch for AgentForge pipeline stages.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\AgentForge\Observability;

use OpenEMR\AgentForge\AgentForgeClock;
use OpenEMR\AgentForge\SystemAgentForgeClock;

final class StageTimer
{
    /** @var array<string, int> */
    private array $startTimesMs = [];

    /** @var array<string, int> */
    private array $timingsMs = [];

    public function __construct(private readonly AgentForgeClock $clock = new SystemAgentForgeClock())
    {
    }

    public function start(string $stage): void
    {
        $this->startTimesMs[$stage] = $this->clock->nowMs();
    }

    public function stop(string $stage): void
    {
        if (!isset($this->startTimesMs[$stage])) {
            return;
        }
        $elapsedMs = $this->clock->nowMs() - $this->startTimesMs[$stage];
        $this->timingsMs[$stage] = ($this->timingsMs[$stage] ?? 0) + max(0, $elapsedMs);
        unset($this->startTimesMs[$stage]);
    }

    /** @return array<string, int> */
    public function timings(): array
    {
        return $this->timingsMs;
    }
}
