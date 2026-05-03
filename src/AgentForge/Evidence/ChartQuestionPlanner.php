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
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

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

    public function __construct(
        private ?ToolSelectionProvider $selector = null,
        private LoggerInterface $logger = new NullLogger(),
    ) {
    }

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

        $selectorPlan = $this->planWithSelector($question, $deadlineMs, $conversationSummary);
        if ($selectorPlan !== null) {
            return $selectorPlan;
        }

        return $this->planDeterministically($question, $deadlineMs, $conversationSummary, 'deterministic', 'fallback_not_needed');
    }

    private function planWithSelector(
        AgentQuestion $question,
        int $deadlineMs,
        ?ConversationTurnSummary $conversationSummary,
    ): ?ChartQuestionPlan {
        if ($this->selector === null) {
            return null;
        }

        try {
            $selection = $this->selector->select(
                new ToolSelectionRequest(
                    $question,
                    self::allowedSections(),
                    'Select only chart sections for the current active patient. Do not select diagnosis, treatment, dosing, medication-change, note-drafting, or cross-patient actions.',
                    $conversationSummary,
                ),
            );
        } catch (ToolSelectionException $exception) {
            $this->logger->warning('AgentForge tool selection failed; deterministic planner fallback used.', [
                'failure_class' => $exception::class,
            ]);

            return $this->planDeterministically(
                $question,
                $deadlineMs,
                $conversationSummary,
                $this->selector->mode(),
                'fallback_used',
                'selector_exception',
            );
        }

        $sections = $this->validSelectedSections($selection->sections);
        if ($sections === []) {
            return $this->planDeterministically(
                $question,
                $deadlineMs,
                $conversationSummary,
                $this->selector->mode(),
                'fallback_used',
                'empty_or_invalid_sections',
            );
        }

        return $this->buildPlan(
            $selection->questionType,
            $sections,
            $deadlineMs,
            null,
            $this->selector->mode(),
            'selected',
        );
    }

    private function planDeterministically(
        AgentQuestion $question,
        int $deadlineMs,
        ?ConversationTurnSummary $conversationSummary,
        string $selectorMode,
        string $selectorResult,
        ?string $selectorFallbackReason = null,
    ): ChartQuestionPlan {
        $normalized = strtolower($question->value);
        if ($this->containsAny($normalized, ['birth weight', 'birthweight'])) {
            return $this->buildPlan('unsupported_fact', [], $deadlineMs, null, $selectorMode, $selectorResult, $selectorFallbackReason);
        }
        if ($this->containsAny($normalized, ['allergy', 'allergies', 'allergic', 'reaction', 'reactions'])) {
            return $this->buildPlan('allergy', [self::SECTION_ALLERGIES], $deadlineMs, null, $selectorMode, $selectorResult, $selectorFallbackReason);
        }
        if ($this->containsAny($normalized, ['medication', 'medications', 'meds', 'prescription', 'prescriptions', 'metformin'])) {
            return $this->buildPlan(
                'medication',
                [self::SECTION_MEDICATIONS, self::SECTION_INACTIVE_MEDICATIONS],
                $deadlineMs,
                null,
                $selectorMode,
                $selectorResult,
                $selectorFallbackReason,
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
            return $this->buildPlan('lab', [self::SECTION_LABS], $deadlineMs, null, $selectorMode, $selectorResult, $selectorFallbackReason);
        }
        if ($this->containsAny($normalized, ['vital', 'vitals', 'blood pressure', 'bp', 'pulse', 'temperature', 'weight', 'height', 'oxygen', 'o2'])) {
            return $this->buildPlan('vital', [self::SECTION_VITALS, self::SECTION_STALE_VITALS], $deadlineMs, null, $selectorMode, $selectorResult, $selectorFallbackReason);
        }
        if ($this->containsAny($normalized, ['problem', 'problems', 'condition', 'conditions', 'comorbid', 'comorbidities'])) {
            return $this->buildPlan('problem', [self::SECTION_DEMOGRAPHICS, self::SECTION_PROBLEMS], $deadlineMs, null, $selectorMode, $selectorResult, $selectorFallbackReason);
        }
        if ($this->containsAny($normalized, ['plan', 'note', 'notes', 'assessment', 'follow-up', 'follow up', 'last visit', 'previous visit', 'history of present illness', 'hpi'])) {
            return $this->buildPlan('last_plan', [self::SECTION_NOTES], $deadlineMs, null, $selectorMode, $selectorResult, $selectorFallbackReason);
        }
        if ($conversationSummary !== null && $this->looksLikeFollowUp($normalized)) {
            $sections = $this->sectionsForPriorQuestionType($conversationSummary->questionType);
            if ($sections !== []) {
                return $this->buildPlan($conversationSummary->questionType, $sections, $deadlineMs, null, $selectorMode, $selectorResult, $selectorFallbackReason);
            }
        }

        return $this->buildPlan('visit_briefing', self::defaultSections(), $deadlineMs, null, $selectorMode, $selectorResult, $selectorFallbackReason);
    }

    /** @param list<string> $sections */
    private function buildPlan(
        string $questionType,
        array $sections,
        int $deadlineMs,
        ?string $refusal = null,
        string $selectorMode = 'deterministic',
        string $selectorResult = 'fallback_not_needed',
        ?string $selectorFallbackReason = null,
    ): ChartQuestionPlan {
        return new ChartQuestionPlan(
            $questionType,
            $sections,
            $deadlineMs,
            $refusal,
            array_values(array_diff(self::defaultSections(), $sections)),
            $selectorMode,
            $selectorResult,
            $selectorFallbackReason,
        );
    }

    /** @return array<string, string> */
    public static function allowedSections(): array
    {
        return [
            self::SECTION_DEMOGRAPHICS => 'Patient identity and basic visit context.',
            self::SECTION_ENCOUNTERS => 'Recent encounter dates and reasons for visit.',
            self::SECTION_PROBLEMS => 'Active charted problems and conditions.',
            self::SECTION_MEDICATIONS => 'Active medications and prescriptions.',
            self::SECTION_INACTIVE_MEDICATIONS => 'Inactive or stopped medication history, labeled separately.',
            self::SECTION_ALLERGIES => 'Documented active allergies and reactions.',
            self::SECTION_LABS => 'Recent lab results and trends.',
            self::SECTION_VITALS => 'Recent vital signs.',
            self::SECTION_STALE_VITALS => 'Last-known older vitals when recent vitals are missing.',
            self::SECTION_NOTES => 'Recent notes and last plan context.',
        ];
    }

    /**
     * @param list<string> $sections
     * @return list<string>
     */
    private function validSelectedSections(array $sections): array
    {
        $allowed = self::allowedSections();
        $valid = [];
        foreach ($sections as $section) {
            if (isset($allowed[$section]) && !in_array($section, $valid, true)) {
                $valid[] = $section;
            }
        }

        return $valid;
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
