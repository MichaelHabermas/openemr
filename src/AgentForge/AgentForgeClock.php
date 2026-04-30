<?php

/**
 * Clock boundary for deterministic AgentForge deadline tests.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\AgentForge;

interface AgentForgeClock
{
    public function nowMs(): int;
}
