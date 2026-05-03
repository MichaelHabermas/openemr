<?php

/**
 * Evidence access plan for one AgentForge chart question.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\AgentForge\Evidence;

final readonly class ChartQuestionPlan
{
    /**
     * @param list<string> $sections
     * @param list<string> $skippedSections
     */
    public function __construct(
        public string $questionType,
        public array $sections,
        public int $deadlineMs,
        public ?string $refusal = null,
        public array $skippedSections = [],
        public string $selectorMode = 'deterministic',
        public string $selectorResult = 'fallback_not_needed',
        public ?string $selectorFallbackReason = null,
    ) {
    }

    public function refused(): bool
    {
        return $this->refusal !== null;
    }
}
