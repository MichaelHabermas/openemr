<?php

/**
 * Input contract for AgentForge chart-section selection.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\AgentForge\Evidence;

use OpenEMR\AgentForge\Conversation\ConversationTurnSummary;
use OpenEMR\AgentForge\Handlers\AgentQuestion;

final readonly class ToolSelectionRequest
{
    /**
     * @param array<string, string> $allowedSections
     */
    public function __construct(
        public AgentQuestion $question,
        public array $allowedSections,
        public string $scopePolicy,
        public ?ConversationTurnSummary $conversationSummary = null,
    ) {
    }
}
