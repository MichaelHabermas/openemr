<?php

/**
 * In-memory AgentForge conversation store for isolated execution paths.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\AgentForge\Conversation;

use OpenEMR\AgentForge\Auth\PatientId;

final class InMemoryConversationStore implements ConversationStore
{
    /** @var array<string, ConversationState> */
    private array $states = [];

    public function __construct(
        private readonly int $ttlMs = 1_800_000,
        private readonly int $maxTurns = 10,
    ) {
    }

    public function start(int $userId, PatientId $patientId, int $nowMs): ConversationState
    {
        $this->pruneExpired($nowMs);
        $state = new ConversationState(
            ConversationId::generate(),
            $userId,
            $patientId,
            $nowMs + $this->ttlMs,
        );
        $this->states[$state->id->value] = $state;

        return $state;
    }

    public function lookup(ConversationId $id, int $nowMs): ConversationLookup
    {
        $state = $this->states[$id->value] ?? null;
        if ($state === null) {
            return ConversationLookup::missing();
        }

        if ($state->expiredAt($nowMs)) {
            unset($this->states[$id->value]);

            return ConversationLookup::expired();
        }

        if ($state->turnCount >= $this->maxTurns) {
            return ConversationLookup::turnLimitExceeded();
        }

        return ConversationLookup::found($state);
    }

    public function recordTurn(ConversationState $state, ConversationTurnSummary $summary, int $nowMs): ConversationState
    {
        $this->pruneExpired($nowMs);
        $updated = $state->withTurn($summary, $nowMs + $this->ttlMs);
        $this->states[$updated->id->value] = $updated;

        return $updated;
    }

    private function pruneExpired(int $nowMs): void
    {
        foreach ($this->states as $id => $state) {
            if ($state->expiredAt($nowMs)) {
                unset($this->states[$id]);
            }
        }
    }
}
