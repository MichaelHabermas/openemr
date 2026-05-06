<?php

/**
 * Test fake monotonic clock that returns a pre-recorded list of tick values.
 *
 * Each call to nowMs() consumes the next tick. Returns 0 once the tick list
 * is exhausted, matching the prior hand-rolled fakes.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Tests\Isolated\AgentForge\Support;

use OpenEMR\AgentForge\Time\MonotonicClock;

final class TickingMonotonicClock implements MonotonicClock
{
    /** @param list<int> $ticks */
    public function __construct(private array $ticks)
    {
    }

    public function nowMs(): int
    {
        if ($this->ticks === []) {
            return 0;
        }

        return array_shift($this->ticks);
    }
}
