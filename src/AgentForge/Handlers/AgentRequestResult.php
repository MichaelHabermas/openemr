<?php

/**
 * AgentForge request handling result.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\AgentForge\Handlers;

use OpenEMR\AgentForge\Observability\AgentTelemetry;

final readonly class AgentRequestResult
{
    public function __construct(
        public AgentResponse $response,
        public int $statusCode,
        public string $decision,
        public ?int $logPatientId,
        public ?AgentTelemetry $telemetry = null,
        public ?string $conversationId = null,
    ) {
    }
}
