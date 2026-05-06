<?php

/**
 * Immutable definition binding a question type to its keywords, sections, and selector label.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\AgentForge\Evidence;

final readonly class QuestionTypeDefinition
{
    /**
     * @param list<string> $keywords
     * @param list<string> $sections
     */
    public function __construct(
        public QuestionType $type,
        public array $keywords,
        public array $sections,
        public string $selectorLabel,
    ) {
    }

    public function matchesKeyword(string $normalizedQuestion): bool
    {
        foreach ($this->keywords as $keyword) {
            if (str_contains($normalizedQuestion, $keyword)) {
                return true;
            }
        }

        return false;
    }

    public function matchesSelector(string $selectedType, string $normalizedQuestion): bool
    {
        return $selectedType === $this->selectorLabel
            || $this->matchesKeyword($normalizedQuestion);
    }
}
