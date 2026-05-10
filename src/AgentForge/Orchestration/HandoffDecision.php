<?php

/**
 * Immutable value object representing a routing decision.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\AgentForge\Orchestration;

final readonly class HandoffDecision
{
    /**
     * @param DecisionType $type The decision type
     * @param string $reason Machine-readable reason code
     * @param array<string, scalar|null> $context Structured context for logging/audit
     * @param ?NodeName $targetNode Target node for handoff decisions
     */
    public function __construct(
        public DecisionType $type,
        public string $reason,
        public array $context = [],
        public ?NodeName $targetNode = null,
    ) {
    }

    /**
     * Create an extraction decision.
     *
     * @param string $reason Machine-readable reason (e.g., 'document_extraction_required')
     * @param array<string, scalar|null> $context
     */
    public static function extract(string $reason, array $context = []): self
    {
        return new self(DecisionType::Extract, $reason, $context, NodeName::IntakeExtractor);
    }

    /**
     * Create a guideline evidence decision.
     *
     * @param string $reason Machine-readable reason (e.g., 'guideline_evidence_required')
     * @param array<string, scalar|null> $context
     */
    public static function guideline(string $reason, array $context = []): self
    {
        return new self(DecisionType::Guideline, $reason, $context, NodeName::EvidenceRetriever);
    }

    /**
     * Create an answer-ready decision.
     *
     * @param string $reason Machine-readable reason (e.g., 'answer_ready')
     * @param array<string, scalar|null> $context
     */
    public static function answer(string $reason, array $context = []): self
    {
        return new self(DecisionType::Answer, $reason, $context, null);
    }

    /**
     * Create a refusal decision.
     *
     * @param string $reason Machine-readable reason (e.g., 'cross_patient_scope')
     * @param array<string, scalar|null> $context
     */
    public static function refuse(string $reason, array $context = []): self
    {
        return new self(DecisionType::Refuse, $reason, $context, null);
    }

    /**
     * Create a hold/wait decision.
     *
     * @param string $reason Machine-readable reason (e.g., 'document_processing')
     * @param array<string, scalar|null> $context
     */
    public static function hold(string $reason, array $context = []): self
    {
        return new self(DecisionType::Hold, $reason, $context, null);
    }

    /**
     * Check if this decision results in a handoff to a worker.
     */
    public function shouldHandoff(): bool
    {
        return $this->type->isHandoff();
    }
}
