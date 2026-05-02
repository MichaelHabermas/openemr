<?php

/**
 * Typed request for the AgentForge chart request shell.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\AgentForge\Handlers;

use OpenEMR\AgentForge\Auth\PatientId;
use OpenEMR\AgentForge\Conversation\ConversationId;
use OpenEMR\AgentForge\Conversation\ConversationTurnSummary;

final readonly class AgentRequest
{
    public function __construct(
        public PatientId $patientId,
        public AgentQuestion $question,
        public ?ConversationId $conversationId = null,
        public ?ConversationTurnSummary $conversationSummary = null,
    ) {
    }

    public function withConversationSummary(?ConversationTurnSummary $summary): self
    {
        return new self(
            $this->patientId,
            $this->question,
            $this->conversationId,
            $summary,
        );
    }
}
