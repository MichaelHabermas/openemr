<?php

/**
 * Selects the minimum chart evidence needed for an AgentForge question.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\AgentForge\Evidence;

use OpenEMR\AgentForge\Handlers\AgentQuestion;
use OpenEMR\AgentForge\Verification\ClinicalAdviceRefusalPolicy;

final readonly class ChartQuestionPlanner
{
    public const SECTION_DEMOGRAPHICS = 'Demographics';
    public const SECTION_PROBLEMS = 'Active problems';
    public const SECTION_MEDICATIONS = 'Active prescriptions';
    public const SECTION_LABS = 'Recent labs';
    public const SECTION_NOTES = 'Recent notes and last plan';

    /** @return list<string> */
    public static function defaultSections(): array
    {
        return [
            self::SECTION_DEMOGRAPHICS,
            self::SECTION_PROBLEMS,
            self::SECTION_MEDICATIONS,
            self::SECTION_LABS,
            self::SECTION_NOTES,
        ];
    }

    public function plan(AgentQuestion $question, int $deadlineMs): ChartQuestionPlan
    {
        $refusal = ClinicalAdviceRefusalPolicy::refusalFor($question->value);
        if ($refusal !== null) {
            return $this->buildPlan('clinical_advice_refusal', [], $deadlineMs, $refusal);
        }

        $normalized = strtolower($question->value);
        if ($this->containsAny($normalized, ['medication', 'medications', 'meds', 'prescription', 'prescriptions', 'metformin'])) {
            return $this->buildPlan('medication', [self::SECTION_MEDICATIONS], $deadlineMs);
        }
        if ($this->containsAny($normalized, ['a1c', 'lab', 'labs', 'laboratory', 'microalbumin', 'glucose', 'result'])) {
            return $this->buildPlan('lab', [self::SECTION_LABS], $deadlineMs);
        }
        if ($this->containsAny($normalized, ['plan', 'note', 'notes', 'assessment', 'follow-up', 'follow up'])) {
            return $this->buildPlan('last_plan', [self::SECTION_NOTES], $deadlineMs);
        }
        if ($this->containsAny($normalized, ['briefing', 'summary', 'summarize', 'overview', 'changed', 'full chart'])) {
            return $this->buildPlan('visit_briefing', self::defaultSections(), $deadlineMs);
        }

        return $this->buildPlan(
            'ambiguous_question',
            [],
            $deadlineMs,
            'Please ask a specific chart question, such as recent labs, active medications, or the last plan.',
        );
    }

    /** @param list<string> $sections */
    private function buildPlan(
        string $questionType,
        array $sections,
        int $deadlineMs,
        ?string $refusal = null,
    ): ChartQuestionPlan {
        return new ChartQuestionPlan(
            $questionType,
            $sections,
            $deadlineMs,
            $refusal,
            array_values(array_diff(self::defaultSections(), $sections)),
        );
    }

    /** @param list<string> $needles */
    private function containsAny(string $value, array $needles): bool
    {
        foreach ($needles as $needle) {
            if (str_contains($value, $needle)) {
                return true;
            }
        }

        return false;
    }
}
