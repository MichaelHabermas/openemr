<?php

/**
 * Exposes sanitized telemetry for the most recent AgentForge handler run.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\AgentForge\Observability;

interface AgentTelemetryProvider
{
    public function lastTelemetry(): ?AgentTelemetry;
}
