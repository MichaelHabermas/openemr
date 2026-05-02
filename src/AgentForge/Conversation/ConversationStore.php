<?php

/**
 * Store for short-lived, server-owned AgentForge conversation state.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\AgentForge\Conversation;

use OpenEMR\AgentForge\Auth\PatientId;

interface ConversationStore
{
    public function start(int $userId, PatientId $patientId, int $nowMs): ConversationState;

    public function lookup(ConversationId $id, int $nowMs): ConversationLookup;

    public function recordTurn(ConversationState $state, ConversationTurnSummary $summary, int $nowMs): ConversationState;
}
