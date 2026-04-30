<?php

/**
 * Monotonic system clock for AgentForge deadlines.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\AgentForge;

final class SystemAgentForgeClock implements AgentForgeClock
{
    public function nowMs(): int
    {
        return (int) floor(hrtime(true) / 1_000_000);
    }
}
