<?php

/**
 * Unified supervisor/router for AgentForge using pluggable handoff policies.
 *
 * Delegates all routing decisions to HandoffPolicy implementations,
 * handling only persistence of decisions and audit trail logging.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\AgentForge\Orchestration;

use OpenEMR\AgentForge\Time\MonotonicClock;

final readonly class Supervisor
{
    public function __construct(
        private HandoffPolicy $policy,
        private SupervisorHandoffRepository $handoffs,
        private MonotonicClock $clock,
    ) {
    }

    /**
     * Route based on policy decision and persist to audit trail.
     *
     * All decision logic is delegated to the injected HandoffPolicy.
     * This method handles:
     * - Policy invocation
     * - Decision logging
     * - Audit trail persistence
     *
     * @param HandoffContext $context The routing context
     * @return HandoffDecision The decision (with logging completed)
     */
    public function route(HandoffContext $context): HandoffDecision
    {
        $startMs = $this->clock->nowMs();

        // Delegate to policy for decision
        $decision = $this->policy->decide($context);

        $latencyMs = $this->clock->nowMs() - $startMs;

        // Log to audit trail if handoff decision
        if ($decision->shouldHandoff()) {
            $this->recordHandoff($context, $decision, $latencyMs);
        }

        return $decision;
    }

    /**
     * Record a handoff decision to the audit repository.
     */
    private function recordHandoff(HandoffContext $context, HandoffDecision $decision, int $latencyMs): void
    {
        if ($decision->targetNode === null) {
            return;
        }

        $this->handoffs->recordRequestHandoff(
            requestId: $this->deriveRequestId($context),
            destinationNode: $decision->targetNode,
            decisionReason: $decision->reason,
            taskType: $context->questionType,
            outcome: 'handoff',
            latencyMs: $latencyMs,
            errorReason: null,
        );
    }

    /**
     * Derive a request ID from context for logging.
     */
    private function deriveRequestId(HandoffContext $context): string
    {
        // Use patient + question hash as synthetic request ID for correlation
        return hash('sha256', sprintf(
            '%d:%s:%s',
            $context->patientId->value,
            $context->question->value,
            $context->isDocumentJob() ? 'doc' : 'chat'
        ));
    }
}
