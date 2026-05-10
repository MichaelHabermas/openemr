<?php

/**
 * Routing decision types for the unified handoff policy.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\AgentForge\Orchestration;

enum DecisionType: string
{
    // Route to intake extractor worker for document extraction
    case Extract = 'extract';

    // Route to evidence retriever worker for guideline/context retrieval
    case Guideline = 'guideline';

    // Answer is ready, no further worker needed
    case Answer = 'answer';

    // Request refused (safety, scope violation)
    case Refuse = 'refuse';

    // Hold/wait for async completion (document processing, identity verification)
    case Hold = 'hold';

    /**
     * Check if this decision type results in a handoff to a worker.
     */
    public function isHandoff(): bool
    {
        return match ($this) {
            self::Extract, self::Guideline => true,
            default => false,
        };
    }

    /**
     * Get the target node for handoff decisions.
     */
    public function targetNode(): ?NodeName
    {
        return match ($this) {
            self::Extract => NodeName::IntakeExtractor,
            self::Guideline => NodeName::EvidenceRetriever,
            default => null,
        };
    }
}
