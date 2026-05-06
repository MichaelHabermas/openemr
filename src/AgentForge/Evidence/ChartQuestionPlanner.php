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
    public const SECTION_CLINICAL_DOCUMENTS = 'Recent clinical documents';
    public const SECTION_VITALS = 'Recent vitals';
    public const SECTION_STALE_VITALS = 'Last-known stale vitals';
    public const SECTION_NOTES = 'Recent notes and last plan';

    /** @var list<string> */
    private const CHANGE_REVIEW_KEYWORDS = [
        'changed since',
        'change since',
        'changes since',
        'anything changed',
        'what changed',
        'new since',
        'since last visit',
        'since previous visit',
    ];

    /** @var list<string> */
    private const PRESCRIBING_CHECK_KEYWORDS = [
        'before prescribing',
        'before i prescribe',
        'double-check before prescribing',
        'double check before prescribing',
        'prescribing',
        'prescribe',
    ];

    /** @var list<string> */
    private const LAB_KEYWORDS = [
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
    ];

    /** @var list<string> */
    private const MEDICATION_KEYWORDS = [
        'medication',
        'medications',
        'meds',
        'prescription',
        'prescriptions',
        'metformin',
    ];

    /** @var list<string> */
    private const ALLERGY_KEYWORDS = [
        'allergy',
        'allergies',
        'allergic',
        'reaction',
        'reactions',
    ];

    /** @var list<string> */
    private const VITAL_KEYWORDS = [
        'vital',
        'vitals',
        'blood pressure',
        'bp',
        'pulse',
        'temperature',
        'weight',
        'height',
        'oxygen',
        'o2',
    ];

    /** @var list<string> */
    private const PROBLEM_KEYWORDS = [
        'problem',
        'problems',
        'condition',
        'conditions',
        'comorbid',
        'comorbidities',
    ];

    /** @var list<string> */
    private const LAST_PLAN_KEYWORDS = [
        'plan',
        'note',
        'notes',
        'assessment',
        'follow-up',
        'follow up',
        'last visit',
        'previous visit',
        'history of present illness',
        'hpi',
    ];

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

        $normalizedSelection = $this->normalizeSelectedSections(
            $question,
            $selection->questionType,
            $sections,
            $conversationSummary,
        );

        return $this->buildPlan(
            $normalizedSelection['question_type'],
            $normalizedSelection['sections'],
            $deadlineMs,
            null,
            $this->selector->mode(),
            'selected',
        );
    }

    /**
     * LLM section selection is the primary planner path, but high-risk chart questions still need
     * deterministic minimum/required evidence guardrails before the plan can reach the agent.
     *
     * @param list<string> $sections
     * @return array{question_type: string, sections: list<string>}
     */
    private function normalizeSelectedSections(
        AgentQuestion $question,
        string $questionType,
        array $sections,
        ?ConversationTurnSummary $conversationSummary,
    ): array {
        $normalized = strtolower($question->value);
        $selectedType = strtolower(trim($questionType));

        if ($this->selectorIndicatesChangeReview($selectedType, $normalized)) {
            return [
                'question_type' => 'follow_up_change_review',
                'sections' => self::changeReviewSections(),
            ];
        }

        if ($this->selectorIndicatesPrescribingCheck($selectedType, $normalized)) {
            return [
                'question_type' => 'pre_prescribing_chart_check',
                'sections' => self::prescribingCheckSections(),
            ];
        }

        if ($this->selectorIndicatesLab($selectedType, $normalized)) {
            return [
                'question_type' => 'lab',
                'sections' => [self::SECTION_LABS],
            ];
        }

        if ($this->selectorIndicatesAllergy($selectedType, $normalized)) {
            return [
                'question_type' => 'allergy',
                'sections' => [self::SECTION_ALLERGIES],
            ];
        }

        if ($this->selectorIndicatesMedication($selectedType, $normalized)) {
            return [
                'question_type' => 'medication',
                'sections' => [self::SECTION_MEDICATIONS, self::SECTION_INACTIVE_MEDICATIONS],
            ];
        }

        if ($this->selectorIndicatesVital($selectedType, $normalized)) {
            return [
                'question_type' => 'vital',
                'sections' => [self::SECTION_VITALS, self::SECTION_STALE_VITALS],
            ];
        }

        if ($this->selectorIndicatesProblem($selectedType, $normalized)) {
            return [
                'question_type' => 'problem',
                'sections' => [self::SECTION_DEMOGRAPHICS, self::SECTION_PROBLEMS],
            ];
        }

        if ($this->selectorIndicatesLastPlan($selectedType, $normalized)) {
            return [
                'question_type' => 'last_plan',
                'sections' => [self::SECTION_NOTES],
            ];
        }

        if ($conversationSummary !== null && $this->looksLikeFollowUp($normalized)) {
            $priorSections = $this->sectionsForPriorQuestionType($conversationSummary->questionType);
            if ($priorSections !== []) {
                return [
                    'question_type' => $conversationSummary->questionType,
                    'sections' => $priorSections,
                ];
            }
        }

        if ($selectedType === 'visit_briefing' || $this->looksLikeVisitBriefing($normalized)) {
            return [
                'question_type' => 'visit_briefing',
                'sections' => self::visitBriefingSections(),
            ];
        }

        return [
            'question_type' => trim($questionType) === '' ? 'visit_briefing' : $questionType,
            'sections' => $sections,
        ];
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
        if ($this->selectorIndicatesChangeReview('', $normalized)) {
            return $this->buildPlan(
                'follow_up_change_review',
                self::changeReviewSections(),
                $deadlineMs,
                null,
                $selectorMode,
                $selectorResult,
                $selectorFallbackReason,
            );
        }
        if ($this->selectorIndicatesPrescribingCheck('', $normalized)) {
            return $this->buildPlan(
                'pre_prescribing_chart_check',
                self::prescribingCheckSections(),
                $deadlineMs,
                null,
                $selectorMode,
                $selectorResult,
                $selectorFallbackReason,
            );
        }
        if ($this->containsAny($normalized, self::ALLERGY_KEYWORDS)) {
            return $this->buildPlan('allergy', [self::SECTION_ALLERGIES], $deadlineMs, null, $selectorMode, $selectorResult, $selectorFallbackReason);
        }
        if ($this->containsAny($normalized, self::MEDICATION_KEYWORDS)) {
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
        if ($this->containsAny($normalized, self::LAB_KEYWORDS)) {
            return $this->buildPlan(
                'lab',
                [self::SECTION_LABS],
                $deadlineMs,
                null,
                $selectorMode,
                $selectorResult,
                $selectorFallbackReason,
            );
        }
        if ($this->containsAny($normalized, self::VITAL_KEYWORDS)) {
            return $this->buildPlan('vital', [self::SECTION_VITALS, self::SECTION_STALE_VITALS], $deadlineMs, null, $selectorMode, $selectorResult, $selectorFallbackReason);
        }
        if ($this->containsAny($normalized, self::PROBLEM_KEYWORDS)) {
            return $this->buildPlan('problem', [self::SECTION_DEMOGRAPHICS, self::SECTION_PROBLEMS], $deadlineMs, null, $selectorMode, $selectorResult, $selectorFallbackReason);
        }
        if ($this->containsAny($normalized, self::LAST_PLAN_KEYWORDS)) {
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
            self::SECTION_CLINICAL_DOCUMENTS => 'Trusted extracted facts from recent clinical documents.',
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

    private function selectorIndicatesChangeReview(string $selectedType, string $value): bool
    {
        return $selectedType === 'follow_up_change_review'
            || $this->containsAny($value, self::CHANGE_REVIEW_KEYWORDS);
    }

    private function selectorIndicatesPrescribingCheck(string $selectedType, string $value): bool
    {
        return $selectedType === 'pre_prescribing_chart_check'
            || $this->containsAny($value, self::PRESCRIBING_CHECK_KEYWORDS);
    }

    private function selectorIndicatesLab(string $selectedType, string $value): bool
    {
        return $selectedType === 'lab'
            || $this->containsAny($value, self::LAB_KEYWORDS);
    }

    private function selectorIndicatesMedication(string $selectedType, string $value): bool
    {
        return $selectedType === 'medication'
            || $this->containsAny($value, self::MEDICATION_KEYWORDS);
    }

    private function selectorIndicatesAllergy(string $selectedType, string $value): bool
    {
        return $selectedType === 'allergy'
            || $this->containsAny($value, self::ALLERGY_KEYWORDS);
    }

    private function selectorIndicatesVital(string $selectedType, string $value): bool
    {
        return $selectedType === 'vital'
            || $this->containsAny($value, self::VITAL_KEYWORDS);
    }

    private function selectorIndicatesProblem(string $selectedType, string $value): bool
    {
        return $selectedType === 'problem'
            || $this->containsAny($value, self::PROBLEM_KEYWORDS);
    }

    private function selectorIndicatesLastPlan(string $selectedType, string $value): bool
    {
        return $selectedType === 'last_plan'
            || $this->containsAny($value, self::LAST_PLAN_KEYWORDS);
    }

    private function looksLikeVisitBriefing(string $value): bool
    {
        return $this->containsAny($value, [
            'visit briefing',
            'briefing',
            'summary of this patient',
            'summarize this patient',
            'tell me about this patient',
            'what should i know',
            'full chart',
        ]);
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
            'visit_briefing' => self::visitBriefingSections(),
            'follow_up_change_review' => self::changeReviewSections(),
            'pre_prescribing_chart_check' => self::prescribingCheckSections(),
            default => [],
        };
    }

    /** @return list<string> */
    private static function visitBriefingSections(): array
    {
        return [
            self::SECTION_DEMOGRAPHICS,
            self::SECTION_ENCOUNTERS,
            self::SECTION_PROBLEMS,
            self::SECTION_MEDICATIONS,
            self::SECTION_ALLERGIES,
            self::SECTION_LABS,
            self::SECTION_VITALS,
            self::SECTION_NOTES,
        ];
    }

    /** @return list<string> */
    private static function changeReviewSections(): array
    {
        return [
            self::SECTION_ENCOUNTERS,
            self::SECTION_LABS,
            self::SECTION_CLINICAL_DOCUMENTS,
            self::SECTION_VITALS,
            self::SECTION_NOTES,
        ];
    }

    /** @return list<string> */
    private static function prescribingCheckSections(): array
    {
        return [
            self::SECTION_PROBLEMS,
            self::SECTION_MEDICATIONS,
            self::SECTION_INACTIVE_MEDICATIONS,
            self::SECTION_ALLERGIES,
            self::SECTION_LABS,
            self::SECTION_VITALS,
            self::SECTION_STALE_VITALS,
        ];
    }
}
