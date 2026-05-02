<?php

/**
 * Final verified drafting result plus telemetry.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\AgentForge\Handlers;

use OpenEMR\AgentForge\Observability\AgentTelemetry;

final readonly class VerifiedDraftingResult
{
    public function __construct(
        public AgentResponse $response,
        public AgentTelemetry $telemetry,
    ) {
    }
}
