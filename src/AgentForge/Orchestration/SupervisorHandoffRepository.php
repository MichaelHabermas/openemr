<?php

/**
 * Persists inspectable AgentForge supervisor handoffs.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\AgentForge\Orchestration;

use OpenEMR\AgentForge\Document\DocumentJob;

interface SupervisorHandoffRepository
{
    public function recordRequestHandoff(
        string $requestId,
        NodeName $destinationNode,
        string $decisionReason,
        string $taskType,
        string $outcome,
        ?int $latencyMs = null,
        ?string $errorReason = null,
    ): int;

    public function record(
        DocumentJob $job,
        SupervisorDecision $decision,
        ?string $requestId = null,
        ?int $latencyMs = null,
        ?string $errorReason = null,
    ): int;
}
