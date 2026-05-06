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

    private QuestionTypeRegistry $registry;

    public function __construct(
        private ?ToolSelectionProvider $selector = null,
        private LoggerInterface $logger = new NullLogger(),
    ) {
        $this->registry = new QuestionTypeRegistry();
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

        $match = $this->registry->matchNormalized($selectedType, $normalized);
        if ($match !== null) {
            return [
                'question_type' => $match->type->value,
                'sections' => $match->sections,
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
        if (str_contains($normalized, 'birth weight') || str_contains($normalized, 'birthweight')) {
            return $this->buildPlan('unsupported_fact', [], $deadlineMs, null, $selectorMode, $selectorResult, $selectorFallbackReason);
        }

        $match = $this->registry->matchDeterministic($normalized);
        if ($match !== null) {
            return $this->buildPlan(
                $match->type->value,
                $match->sections,
                $deadlineMs,
                null,
                $selectorMode,
                $selectorResult,
                $selectorFallbackReason,
            );
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

    private function looksLikeFollowUp(string $value): bool
    {
        return (bool) preg_match(
            '/\b(what about|how about|and (her|his|their|those|that|them|it)|those|that|them|it|her|his|their)\b/',
            $value,
        );
    }

    private function looksLikeVisitBriefing(string $value): bool
    {
        return str_contains($value, 'visit briefing')
            || str_contains($value, 'briefing')
            || str_contains($value, 'summary of this patient')
            || str_contains($value, 'summarize this patient')
            || str_contains($value, 'tell me about this patient')
            || str_contains($value, 'what should i know')
            || str_contains($value, 'full chart');
    }

    /** @return list<string> */
    private function sectionsForPriorQuestionType(string $questionType): array
    {
        $definition = $this->registry->findByValue($questionType);
        if ($definition !== null) {
            return $definition->sections;
        }

        return match ($questionType) {
            'visit_briefing' => self::visitBriefingSections(),
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
}
