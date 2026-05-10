<?php

/**
 * Strategy interface for routing/handoff decisions.
 *
 * Implementations encapsulate the decision logic for when to:
 * - Extract from documents
 * - Retrieve guideline evidence
 * - Provide direct answers
 * - Refuse unsafe requests
 * - Hold for async processing
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\AgentForge\Orchestration;

interface HandoffPolicy
{
    /**
     * Make a routing decision based on the provided context.
     *
     * This method must be deterministic and side-effect free.
     * All logging and persistence happens in the Supervisor after the decision.
     *
     * @param HandoffContext $context All inputs needed for the decision
     * @return HandoffDecision The routing decision with reason and context
     */
    public function decide(HandoffContext $context): HandoffDecision;
}
