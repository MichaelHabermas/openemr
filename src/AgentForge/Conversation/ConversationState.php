<?php

/**
 * Patient-bound AgentForge conversation state.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\AgentForge\Conversation;

use OpenEMR\AgentForge\Auth\PatientId;

final readonly class ConversationState
{
    public function __construct(
        public ConversationId $id,
        public int $userId,
        public PatientId $patientId,
        public int $expiresAtMs,
        public int $turnCount = 0,
        public ?ConversationTurnSummary $summary = null,
    ) {
    }

    public function expiredAt(int $nowMs): bool
    {
        return $nowMs >= $this->expiresAtMs;
    }

    public function boundTo(int $userId, PatientId $patientId): bool
    {
        return $this->userId === $userId && $this->patientId->value === $patientId->value;
    }

    public function withTurn(ConversationTurnSummary $summary, int $expiresAtMs): self
    {
        return new self(
            $this->id,
            $this->userId,
            $this->patientId,
            $expiresAtMs,
            $this->turnCount + 1,
            $summary,
        );
    }
}
