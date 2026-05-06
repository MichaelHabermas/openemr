<?php

/**
 * Test fake monotonic clock that starts at a fixed value and advances on demand.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Tests\Isolated\AgentForge\Support;

use OpenEMR\AgentForge\Time\MonotonicClock;

final class AdvanceableMonotonicClock implements MonotonicClock
{
    public function __construct(private int $nowMs = 0)
    {
    }

    public function advance(int $ms): void
    {
        $this->nowMs += $ms;
    }

    public function nowMs(): int
    {
        return $this->nowMs;
    }
}
