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
    public const SECTION_MEDICATIONS = 'Active medications';
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
            return new ChartQuestionPlan('clinical_advice_refusal', [], $deadlineMs, $refusal);
        }

        $normalized = strtolower($question->value);
        if (str_contains($normalized, 'medication') || str_contains($normalized, 'metformin')) {
            return new ChartQuestionPlan('medication', [self::SECTION_MEDICATIONS], $deadlineMs);
        }
        if (str_contains($normalized, 'a1c') || str_contains($normalized, 'lab') || str_contains($normalized, 'microalbumin')) {
            return new ChartQuestionPlan('lab', [self::SECTION_LABS], $deadlineMs);
        }
        if (str_contains($normalized, 'plan') || str_contains($normalized, 'note')) {
            return new ChartQuestionPlan('last_plan', [self::SECTION_NOTES], $deadlineMs);
        }
        if (str_contains($normalized, 'briefing') || str_contains($normalized, 'changed')) {
            return new ChartQuestionPlan('visit_briefing', self::defaultSections(), $deadlineMs);
        }

        return new ChartQuestionPlan('chart_question', self::defaultSections(), $deadlineMs);
    }
}
