<?php

/**
 * Compact non-transcript summary for one AgentForge conversation.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\AgentForge\Conversation;

use OpenEMR\AgentForge\Observability\AgentTelemetry;

final readonly class ConversationTurnSummary
{
    /**
     * @param list<string> $sourceIds
     * @param list<string> $missingOrUncheckedSections
     * @param list<string> $refusalsOrWarnings
     */
    public function __construct(
        public string $questionType,
        public array $sourceIds = [],
        public array $missingOrUncheckedSections = [],
        public array $refusalsOrWarnings = [],
    ) {
    }

    /**
     * @param list<string> $missingOrUncheckedSections
     * @param list<string> $refusalsOrWarnings
     */
    public static function fromTelemetry(
        ?AgentTelemetry $telemetry,
        array $missingOrUncheckedSections,
        array $refusalsOrWarnings,
    ): self {
        if ($telemetry === null) {
            return new self(
                'not_classified',
                [],
                $missingOrUncheckedSections,
                $refusalsOrWarnings,
            );
        }

        return new self(
            $telemetry->questionType,
            $telemetry->sourceIds,
            $missingOrUncheckedSections,
            $refusalsOrWarnings,
        );
    }

    /** @return array<string, mixed> */
    public function toPromptArray(): array
    {
        return [
            'prior_question_type' => $this->questionType,
            'prior_cited_source_ids' => $this->sourceIds,
            'prior_missing_or_unchecked_sections' => $this->missingOrUncheckedSections,
            'prior_refusals_or_warnings' => $this->refusalsOrWarnings,
            'grounding_rule' => 'Use this only to interpret follow-up intent. It is not evidence.',
        ];
    }
}
