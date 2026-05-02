<?php

/**
 * PHP-session-backed AgentForge conversation store.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\AgentForge\Conversation;

use OpenEMR\AgentForge\Auth\PatientId;
use OpenEMR\Common\Session\SessionUtil;
use OpenEMR\Common\Session\SessionWrapperFactory;
use Symfony\Component\HttpFoundation\Session\SessionInterface;

final class SessionConversationStore implements ConversationStore
{
    private const SESSION_KEY = 'agent_forge_conversations';

    public function __construct(
        private readonly int $ttlMs = 1_800_000,
        private readonly int $maxTurns = 10,
    ) {
    }

    public function start(int $userId, PatientId $patientId, int $nowMs): ConversationState
    {
        $state = new ConversationState(
            ConversationId::generate(),
            $userId,
            $patientId,
            $nowMs + $this->ttlMs,
        );
        $this->writeState($state, $nowMs);

        return $state;
    }

    public function lookup(ConversationId $id, int $nowMs): ConversationLookup
    {
        $state = $this->readState($id);
        if ($state === null) {
            return ConversationLookup::missing();
        }

        if ($state->expiredAt($nowMs)) {
            $states = $this->readRawStates();
            unset($states[$id->value]);
            $this->writeRawStates($states);

            return ConversationLookup::expired();
        }

        if ($state->turnCount >= $this->maxTurns) {
            return ConversationLookup::turnLimitExceeded();
        }

        return ConversationLookup::found($state);
    }

    public function recordTurn(ConversationState $state, ConversationTurnSummary $summary, int $nowMs): ConversationState
    {
        $updated = $state->withTurn($summary, $nowMs + $this->ttlMs);
        $this->writeState($updated, $nowMs);

        return $updated;
    }

    private function readState(ConversationId $id): ?ConversationState
    {
        $raw = $this->readRawStates()[$id->value] ?? null;
        if (!is_array($raw)) {
            return null;
        }

        $rawUserId = $raw['user_id'] ?? null;
        $rawPatientId = $raw['patient_id'] ?? null;
        $userId = $this->positiveInt($rawUserId);
        $patientId = $this->positiveInt($rawPatientId);
        if ($userId === null || $patientId === null) {
            return null;
        }

        $expiresAtMs = $this->nonNegativeInt($raw['expires_at_ms'] ?? null);
        if ($expiresAtMs === null) {
            return null;
        }

        $turnCount = $this->nonNegativeInt($raw['turn_count'] ?? 0);
        if ($turnCount === null) {
            return null;
        }

        return new ConversationState(
            $id,
            $userId,
            new PatientId($patientId),
            $expiresAtMs,
            $turnCount,
            $this->readSummary($raw['summary'] ?? null),
        );
    }

    private function readSummary(mixed $raw): ?ConversationTurnSummary
    {
        if (!is_array($raw)) {
            return null;
        }

        $questionType = $raw['question_type'] ?? 'not_classified';

        return new ConversationTurnSummary(
            is_string($questionType) ? $questionType : 'not_classified',
            $this->stringList($raw['source_ids'] ?? []),
            $this->stringList($raw['missing_or_unchecked_sections'] ?? []),
            $this->stringList($raw['refusals_or_warnings'] ?? []),
        );
    }

    private function writeState(ConversationState $state, int $nowMs): void
    {
        $states = $this->pruneExpiredRawStates($this->readRawStates(), $nowMs);
        $states[$state->id->value] = [
            'user_id' => $state->userId,
            'patient_id' => $state->patientId->value,
            'expires_at_ms' => $state->expiresAtMs,
            'turn_count' => $state->turnCount,
            'summary' => $state->summary === null ? null : [
                'question_type' => $state->summary->questionType,
                'source_ids' => $state->summary->sourceIds,
                'missing_or_unchecked_sections' => $state->summary->missingOrUncheckedSections,
                'refusals_or_warnings' => $state->summary->refusalsOrWarnings,
            ],
        ];
        $this->writeRawStates($states);
    }

    /**
     * @param array<string, mixed> $states
     * @return array<string, mixed>
     */
    private function pruneExpiredRawStates(array $states, int $nowMs): array
    {
        foreach ($states as $id => $raw) {
            $expiresAtMs = is_array($raw) ? ($raw['expires_at_ms'] ?? null) : null;
            $expiresAtMsInt = $this->nonNegativeInt($expiresAtMs);
            if ($expiresAtMsInt === null || $expiresAtMsInt <= $nowMs) {
                unset($states[$id]);
            }
        }

        return $states;
    }

    /** @return array<string, mixed> */
    private function readRawStates(): array
    {
        $raw = $this->activeSession()->get(self::SESSION_KEY, []);
        if (!is_array($raw)) {
            return [];
        }

        $states = [];
        foreach ($raw as $key => $value) {
            if (is_string($key)) {
                $states[$key] = $value;
            }
        }

        return $states;
    }

    /** @param array<string, mixed> $states */
    private function writeRawStates(array $states): void
    {
        SessionUtil::setSensitiveSession(self::SESSION_KEY, $states);
    }

    private function activeSession(): SessionInterface
    {
        return SessionWrapperFactory::getInstance()->getActiveSession();
    }

    /** @return list<string> */
    private function stringList(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        return array_values(array_filter($value, 'is_string'));
    }

    private function positiveInt(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value > 0 ? $value : null;
        }

        if (!is_string($value) || !ctype_digit($value) || $value === '0') {
            return null;
        }

        return (int) $value;
    }

    private function nonNegativeInt(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value >= 0 ? $value : null;
        }

        if (!is_string($value) || !ctype_digit($value)) {
            return null;
        }

        return (int) $value;
    }
}
