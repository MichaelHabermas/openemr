<?php

/**
 * Parses untrusted HTTP input into an AgentForge request.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\AgentForge\Handlers;

use DomainException;
use OpenEMR\AgentForge\Auth\PatientId;
use OpenEMR\AgentForge\Conversation\ConversationId;

final class AgentRequestParser implements AgentRequestParserInterface
{
    /** @param array<string, mixed> $input */
    public function parse(array $input): AgentRequest
    {
        $rawPatientId = $input['patient_id'] ?? null;
        if (!is_scalar($rawPatientId) || filter_var($rawPatientId, FILTER_VALIDATE_INT) === false) {
            throw new DomainException('Patient id is required.');
        }

        $rawQuestion = $input['question'] ?? null;
        if (!is_scalar($rawQuestion)) {
            throw new DomainException('Question is required.');
        }

        $conversationId = null;
        $rawConversationId = $input['conversation_id'] ?? null;
        if ($rawConversationId !== null && $rawConversationId !== '') {
            if (!is_scalar($rawConversationId)) {
                throw new DomainException('Conversation id is invalid.');
            }
            $conversationId = new ConversationId((string) $rawConversationId);
        }

        return new AgentRequest(
            new PatientId((int) $rawPatientId),
            new AgentQuestion((string) $rawQuestion),
            $conversationId,
        );
    }
}
