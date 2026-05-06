<?php

/**
 * Minimal request surface for draft providers — carries only what drafting needs.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\AgentForge\ResponseGeneration;

use OpenEMR\AgentForge\Auth\PatientId;
use OpenEMR\AgentForge\Conversation\ConversationTurnSummary;
use OpenEMR\AgentForge\Handlers\AgentQuestion;

final readonly class DraftRequest
{
    public function __construct(
        public AgentQuestion $question,
        public PatientId $patientId,
        public ?ConversationTurnSummary $conversationSummary = null,
    ) {
    }
}
