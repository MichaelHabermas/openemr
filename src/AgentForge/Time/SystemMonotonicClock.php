<?php

/**
 * Production monotonic clock backed by hrtime().
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\AgentForge\Time;

final class SystemMonotonicClock implements MonotonicClock
{
    public function nowMs(): int
    {
        return (int) floor(hrtime(true) / 1_000_000);
    }
}
