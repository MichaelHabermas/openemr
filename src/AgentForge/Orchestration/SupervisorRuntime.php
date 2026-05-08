<?php

/**
 * Couples supervisor routing decisions with their audit trail.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\AgentForge\Orchestration;

use OpenEMR\AgentForge\Document\DocumentJob;

final readonly class SupervisorRuntime
{
    public function __construct(
        private Supervisor $supervisor,
        private SupervisorHandoffRepository $handoffs,
    ) {
    }

    public function inspect(DocumentJob $job, bool $trustedForEvidence): SupervisorDecision
    {
        return $this->supervisor->decide($job, $trustedForEvidence);
    }

    public function record(
        DocumentJob $job,
        SupervisorDecision $decision,
        ?string $requestId = null,
        ?int $latencyMs = null,
        ?string $errorReason = null,
    ): ?int {
        if (!$decision->shouldHandoff()) {
            return null;
        }

        return $this->handoffs->record($job, $decision, $requestId, $latencyMs, $errorReason);
    }

    public function recordRequestHandoff(
        string $requestId,
        NodeName $destinationNode,
        string $decisionReason,
        string $taskType,
        string $outcome,
        ?int $latencyMs = null,
        ?string $errorReason = null,
    ): int {
        return $this->handoffs->recordRequestHandoff(
            $requestId,
            $destinationNode,
            $decisionReason,
            $taskType,
            $outcome,
            $latencyMs,
            $errorReason,
        );
    }
}
