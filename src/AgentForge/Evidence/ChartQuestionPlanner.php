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

use OpenEMR\AgentForge\Conversation\ConversationTurnSummary;
use OpenEMR\AgentForge\Handlers\AgentQuestion;
use OpenEMR\AgentForge\Verification\ClinicalAdviceRefusalPolicy;

final readonly class ChartQuestionPlanner
{
    public const SECTION_DEMOGRAPHICS = 'Demographics';
    public const SECTION_ENCOUNTERS = 'Recent encounters';
    public const SECTION_PROBLEMS = 'Active problems';
    public const SECTION_MEDICATIONS = 'Active medications';
    public const SECTION_INACTIVE_MEDICATIONS = 'Inactive medication history';
    public const SECTION_ALLERGIES = 'Allergies';
    public const SECTION_LABS = 'Recent labs';
    public const SECTION_VITALS = 'Recent vitals';
    public const SECTION_STALE_VITALS = 'Last-known stale vitals';
    public const SECTION_NOTES = 'Recent notes and last plan';

    /** @return list<string> */
    public static function defaultSections(): array
    {
        return [
            self::SECTION_DEMOGRAPHICS,
            self::SECTION_ENCOUNTERS,
            self::SECTION_PROBLEMS,
            self::SECTION_MEDICATIONS,
            self::SECTION_INACTIVE_MEDICATIONS,
            self::SECTION_ALLERGIES,
            self::SECTION_LABS,
            self::SECTION_VITALS,
            self::SECTION_STALE_VITALS,
            self::SECTION_NOTES,
        ];
    }

    public function plan(AgentQuestion $question, int $deadlineMs, ?ConversationTurnSummary $conversationSummary = null): ChartQuestionPlan
    {
        $refusal = ClinicalAdviceRefusalPolicy::refusalFor($question->value);
        if ($refusal !== null) {
            return $this->buildPlan('clinical_advice_refusal', [], $deadlineMs, $refusal);
        }

        $normalized = strtolower($question->value);
        if ($this->containsAny($normalized, ['allergy', 'allergies', 'allergic', 'reaction', 'reactions'])) {
            return $this->buildPlan('allergy', [self::SECTION_ALLERGIES], $deadlineMs);
        }
        if ($this->containsAny($normalized, ['medication', 'medications', 'meds', 'prescription', 'prescriptions', 'metformin'])) {
            return $this->buildPlan(
                'medication',
                [self::SECTION_MEDICATIONS, self::SECTION_INACTIVE_MEDICATIONS],
                $deadlineMs,
            );
        }
        if ($this->containsAny($normalized, [
            'a1c',
            'lab',
            'labs',
            'laboratory',
            'microalbumin',
            'glucose',
            'result',
            'sodium',
            'potassium',
            'creatinine',
            'cholesterol',
            'hemoglobin',
            'panel',
        ])) {
            return $this->buildPlan('lab', [self::SECTION_LABS], $deadlineMs);
        }
        if ($this->containsAny($normalized, ['vital', 'vitals', 'blood pressure', 'bp', 'pulse', 'temperature', 'weight', 'height', 'oxygen', 'o2'])) {
            return $this->buildPlan('vital', [self::SECTION_VITALS, self::SECTION_STALE_VITALS], $deadlineMs);
        }
        if ($this->containsAny($normalized, ['problem', 'problems', 'condition', 'conditions', 'comorbid', 'comorbidities'])) {
            return $this->buildPlan('problem', [self::SECTION_DEMOGRAPHICS, self::SECTION_PROBLEMS], $deadlineMs);
        }
        if ($this->containsAny($normalized, ['plan', 'note', 'notes', 'assessment', 'follow-up', 'follow up', 'last visit', 'previous visit', 'history of present illness', 'hpi'])) {
            return $this->buildPlan('last_plan', [self::SECTION_NOTES], $deadlineMs);
        }
        if ($conversationSummary !== null && $this->looksLikeFollowUp($normalized)) {
            $sections = $this->sectionsForPriorQuestionType($conversationSummary->questionType);
            if ($sections !== []) {
                return $this->buildPlan($conversationSummary->questionType, $sections, $deadlineMs);
            }
        }

        return $this->buildPlan('visit_briefing', self::defaultSections(), $deadlineMs);
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

    private function looksLikeFollowUp(string $value): bool
    {
        return (bool) preg_match(
            '/\b(what about|how about|and (her|his|their|those|that|them|it)|those|that|them|it|her|his|their)\b/',
            $value,
        );
    }

    /** @return list<string> */
    private function sectionsForPriorQuestionType(string $questionType): array
    {
        return match ($questionType) {
            'allergy' => [self::SECTION_ALLERGIES],
            'medication' => [self::SECTION_MEDICATIONS, self::SECTION_INACTIVE_MEDICATIONS],
            'lab' => [self::SECTION_LABS],
            'vital' => [self::SECTION_VITALS, self::SECTION_STALE_VITALS],
            'problem' => [self::SECTION_DEMOGRAPHICS, self::SECTION_PROBLEMS],
            'last_plan' => [self::SECTION_NOTES],
            default => [],
        };
    }
}
