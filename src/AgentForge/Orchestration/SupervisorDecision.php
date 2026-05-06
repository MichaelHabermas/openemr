<?php

/**
 * Deterministic supervisor routing decision for AgentForge clinical documents.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\AgentForge\Orchestration;

use InvalidArgumentException;
final readonly class SupervisorDecision
{
    /**
     * @param array<string, scalar|null> $context
     */
    private function __construct(
        public string $decision,
        public ?NodeName $targetNode,
        public string $reason,
        public array $context,
    ) {
        if ($decision === '') {
            throw new InvalidArgumentException('Supervisor decisions require a decision label.');
        }

        if ($reason === '') {
            throw new InvalidArgumentException('Supervisor decisions require a reason.');
        }
    }

    /**
     * @param array<string, scalar|null> $context
     */
    public static function handoff(NodeName $targetNode, string $reason, array $context = []): self
    {
        return new self('handoff', $targetNode, $reason, $context);
    }

    /**
     * @param array<string, scalar|null> $context
     */
    public static function hold(string $reason, array $context = []): self
    {
        return new self('hold', null, $reason, $context);
    }

    public function shouldHandoff(): bool
    {
        return $this->targetNode !== null;
    }
}
