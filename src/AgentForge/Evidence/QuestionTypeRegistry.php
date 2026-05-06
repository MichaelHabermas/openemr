<?php

/**
 * Central registry of question type definitions with deterministic and normalization orderings.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\AgentForge\Evidence;

final class QuestionTypeRegistry
{
    /** @var array<string, QuestionTypeDefinition> */
    private array $definitions;

    /** @var list<QuestionType> */
    private array $deterministicOrder;

    /** @var list<QuestionType> */
    private array $normalizationOrder;

    public function __construct()
    {
        $this->definitions = [];
        foreach (self::buildDefinitions() as $definition) {
            $this->definitions[$definition->type->value] = $definition;
        }

        // planDeterministically checks: allergy → medication → lab
        $this->deterministicOrder = [
            QuestionType::ChangeReview,
            QuestionType::PrescribingCheck,
            QuestionType::Allergy,
            QuestionType::Medication,
            QuestionType::Lab,
            QuestionType::Vital,
            QuestionType::Problem,
            QuestionType::LastPlan,
        ];

        // normalizeSelectedSections checks: lab → allergy → medication
        $this->normalizationOrder = [
            QuestionType::ChangeReview,
            QuestionType::PrescribingCheck,
            QuestionType::Lab,
            QuestionType::Allergy,
            QuestionType::Medication,
            QuestionType::Vital,
            QuestionType::Problem,
            QuestionType::LastPlan,
        ];
    }

    public function get(QuestionType $type): QuestionTypeDefinition
    {
        return $this->definitions[$type->value];
    }

    public function findByValue(string $value): ?QuestionTypeDefinition
    {
        return $this->definitions[$value] ?? null;
    }

    public function matchDeterministic(string $normalizedQuestion): ?QuestionTypeDefinition
    {
        foreach ($this->deterministicOrder as $type) {
            $definition = $this->definitions[$type->value];
            if ($definition->matchesKeyword($normalizedQuestion)) {
                return $definition;
            }
        }

        return null;
    }

    public function matchNormalized(string $selectedType, string $normalizedQuestion): ?QuestionTypeDefinition
    {
        foreach ($this->normalizationOrder as $type) {
            $definition = $this->definitions[$type->value];
            if ($definition->matchesSelector($selectedType, $normalizedQuestion)) {
                return $definition;
            }
        }

        return null;
    }

    /** @return list<QuestionTypeDefinition> */
    private static function buildDefinitions(): array
    {
        return [
            new QuestionTypeDefinition(
                QuestionType::ChangeReview,
                [
                    'changed since',
                    'change since',
                    'changes since',
                    'anything changed',
                    'what changed',
                    'new since',
                    'since last visit',
                    'since previous visit',
                ],
                [
                    ChartQuestionPlanner::SECTION_ENCOUNTERS,
                    ChartQuestionPlanner::SECTION_LABS,
                    ChartQuestionPlanner::SECTION_CLINICAL_DOCUMENTS,
                    ChartQuestionPlanner::SECTION_VITALS,
                    ChartQuestionPlanner::SECTION_NOTES,
                ],
                'follow_up_change_review',
            ),
            new QuestionTypeDefinition(
                QuestionType::PrescribingCheck,
                [
                    'before prescribing',
                    'before i prescribe',
                    'double-check before prescribing',
                    'double check before prescribing',
                    'prescribing',
                    'prescribe',
                ],
                [
                    ChartQuestionPlanner::SECTION_PROBLEMS,
                    ChartQuestionPlanner::SECTION_MEDICATIONS,
                    ChartQuestionPlanner::SECTION_INACTIVE_MEDICATIONS,
                    ChartQuestionPlanner::SECTION_ALLERGIES,
                    ChartQuestionPlanner::SECTION_LABS,
                    ChartQuestionPlanner::SECTION_VITALS,
                    ChartQuestionPlanner::SECTION_STALE_VITALS,
                ],
                'pre_prescribing_chart_check',
            ),
            new QuestionTypeDefinition(
                QuestionType::Lab,
                [
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
                ],
                [ChartQuestionPlanner::SECTION_LABS],
                'lab',
            ),
            new QuestionTypeDefinition(
                QuestionType::Medication,
                [
                    'medication',
                    'medications',
                    'meds',
                    'prescription',
                    'prescriptions',
                    'metformin',
                ],
                [
                    ChartQuestionPlanner::SECTION_MEDICATIONS,
                    ChartQuestionPlanner::SECTION_INACTIVE_MEDICATIONS,
                ],
                'medication',
            ),
            new QuestionTypeDefinition(
                QuestionType::Allergy,
                [
                    'allergy',
                    'allergies',
                    'allergic',
                    'reaction',
                    'reactions',
                ],
                [ChartQuestionPlanner::SECTION_ALLERGIES],
                'allergy',
            ),
            new QuestionTypeDefinition(
                QuestionType::Vital,
                [
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
                ],
                [
                    ChartQuestionPlanner::SECTION_VITALS,
                    ChartQuestionPlanner::SECTION_STALE_VITALS,
                ],
                'vital',
            ),
            new QuestionTypeDefinition(
                QuestionType::Problem,
                [
                    'problem',
                    'problems',
                    'condition',
                    'conditions',
                    'comorbid',
                    'comorbidities',
                ],
                [
                    ChartQuestionPlanner::SECTION_DEMOGRAPHICS,
                    ChartQuestionPlanner::SECTION_PROBLEMS,
                ],
                'problem',
            ),
            new QuestionTypeDefinition(
                QuestionType::LastPlan,
                [
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
                ],
                [ChartQuestionPlanner::SECTION_NOTES],
                'last_plan',
            ),
        ];
    }
}
