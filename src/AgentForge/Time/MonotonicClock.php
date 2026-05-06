<?php

/**
 * Monotonic clock boundary for AgentForge deadline arithmetic.
 *
 * Reports milliseconds from a monotonic source (e.g. hrtime). Values are not
 * comparable to wall-clock time and have no defined epoch — only differences
 * between two readings are meaningful. For wall-clock reads (timestamps,
 * audit logs, hydrated DateTimeImmutable values), use Psr\Clock\ClockInterface.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\AgentForge\Time;

interface MonotonicClock
{
    public function nowMs(): int;
}
