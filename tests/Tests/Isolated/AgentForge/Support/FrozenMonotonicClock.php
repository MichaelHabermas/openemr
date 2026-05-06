<?php

/**
 * Test fake monotonic clock that always returns the same milliseconds value.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Tests\Isolated\AgentForge\Support;

use OpenEMR\AgentForge\Time\MonotonicClock;

final readonly class FrozenMonotonicClock implements MonotonicClock
{
    public function __construct(private int $nowMs = 1_000)
    {
    }

    public function nowMs(): int
    {
        return $this->nowMs;
    }
}
