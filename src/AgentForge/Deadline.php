<?php

/**
 * Wall-clock deadline for AgentForge requests.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\AgentForge;

use OpenEMR\AgentForge\Time\MonotonicClock;

final readonly class Deadline
{
    private int $startMs;

    public function __construct(
        private MonotonicClock $clock,
        public int $budgetMs,
    ) {
        $this->startMs = $clock->nowMs();
    }

    public function remainingMs(): int
    {
        if ($this->budgetMs < 0) {
            return PHP_INT_MAX;
        }

        return $this->budgetMs - ($this->clock->nowMs() - $this->startMs);
    }

    public function exceeded(): bool
    {
        if ($this->budgetMs < 0) {
            return false;
        }

        return ($this->clock->nowMs() - $this->startMs) > $this->budgetMs;
    }

    /**
     * Remaining time in seconds, clamped to a sane minimum so HTTP clients
     * still report a network failure rather than a zero-byte timeout.
     */
    public function remainingSeconds(float $minimumSeconds = 0.001): float
    {
        if ($this->budgetMs < 0) {
            return PHP_INT_MAX;
        }

        $remaining = max(0, $this->remainingMs());

        return max($minimumSeconds, $remaining / 1000.0);
    }
}
