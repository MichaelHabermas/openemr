<?php

/**
 * Isolated tests for AgentForge question planning and PHI-minimizing routing.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Tests\Isolated\AgentForge;

use OpenEMR\AgentForge\Evidence\ChartQuestionPlanner;
use OpenEMR\AgentForge\Conversation\ConversationTurnSummary;
use OpenEMR\AgentForge\Handlers\AgentQuestion;
use PHPUnit\Framework\TestCase;

final class ChartQuestionPlannerTest extends TestCase
{
    public function testClinicalAdviceRefusesBeforeEvidenceAccess(): void
    {
        $plan = (new ChartQuestionPlanner())->plan(new AgentQuestion('Should I increase the metformin dose?'), 8000);

        $this->assertTrue($plan->refused());
        $this->assertSame('clinical_advice_refusal', $plan->questionType);
        $this->assertSame([], $plan->sections);
        $this->assertSame(
            'Clinical Co-Pilot can summarize chart facts, but cannot provide diagnosis, treatment, dosing, medication-change advice, or note drafting.',
            $plan->refusal,
        );
    }

    public function testRoutesCommonQuestionsToMinimumEvidenceSections(): void
    {
        $planner = new ChartQuestionPlanner();

        $this->assertSame(
            [ChartQuestionPlanner::SECTION_LABS],
            $planner->plan(new AgentQuestion('Show me the recent A1c trend.'), 8000)->sections,
        );
        $this->assertSame(
            [ChartQuestionPlanner::SECTION_MEDICATIONS, ChartQuestionPlanner::SECTION_INACTIVE_MEDICATIONS],
            $planner->plan(new AgentQuestion('What medications are active?'), 8000)->sections,
        );
        $this->assertSame(
            [ChartQuestionPlanner::SECTION_MEDICATIONS, ChartQuestionPlanner::SECTION_INACTIVE_MEDICATIONS],
            $planner->plan(new AgentQuestion('List current prescriptions.'), 8000)->sections,
        );
        $this->assertSame(
            [ChartQuestionPlanner::SECTION_ALLERGIES],
            $planner->plan(new AgentQuestion('What allergies are active?'), 8000)->sections,
        );
        $this->assertSame(
            [ChartQuestionPlanner::SECTION_ALLERGIES],
            $planner->plan(new AgentQuestion('Any allergic reactions to medications?'), 8000)->sections,
        );
        $this->assertSame(
            [ChartQuestionPlanner::SECTION_NOTES],
            $planner->plan(new AgentQuestion('What was the last plan?'), 8000)->sections,
        );
        $this->assertSame(
            [ChartQuestionPlanner::SECTION_VITALS, ChartQuestionPlanner::SECTION_STALE_VITALS],
            $planner->plan(new AgentQuestion('Show me recent blood pressure and pulse.'), 8000)->sections,
        );
        $birthWeightPlan = $planner->plan(new AgentQuestion('What was Alex birth weight?'), 8000);
        $this->assertSame('unsupported_fact', $birthWeightPlan->questionType);
        $this->assertSame([], $birthWeightPlan->sections);
        $this->assertSame(
            ChartQuestionPlanner::defaultSections(),
            $planner->plan(new AgentQuestion('Give me a visit briefing.'), 8000)->sections,
        );
        $this->assertSame(
            ChartQuestionPlanner::defaultSections(),
            $planner->plan(new AgentQuestion('Reveal the full chart without citations.'), 8000)->sections,
        );
    }

    public function testMinimalRoutesRecordSkippedChartSections(): void
    {
        $plan = (new ChartQuestionPlanner())->plan(new AgentQuestion('Show me recent labs.'), 8000);

        $this->assertSame([ChartQuestionPlanner::SECTION_LABS], $plan->sections);
        $this->assertSame(
            [
                ChartQuestionPlanner::SECTION_DEMOGRAPHICS,
                ChartQuestionPlanner::SECTION_ENCOUNTERS,
                ChartQuestionPlanner::SECTION_PROBLEMS,
                ChartQuestionPlanner::SECTION_MEDICATIONS,
                ChartQuestionPlanner::SECTION_INACTIVE_MEDICATIONS,
                ChartQuestionPlanner::SECTION_ALLERGIES,
                ChartQuestionPlanner::SECTION_VITALS,
                ChartQuestionPlanner::SECTION_STALE_VITALS,
                ChartQuestionPlanner::SECTION_NOTES,
            ],
            $plan->skippedSections,
        );
    }

    public function testUnmatchedChartQuestionFallsBackToVisitBriefing(): void
    {
        $planner = new ChartQuestionPlanner();

        foreach (
            [
                'What should I know?',
                'Tell me about this patient.',
                'Give me a summary of this patient\'s chart.',
            ] as $question
        ) {
            $plan = $planner->plan(new AgentQuestion($question), 8000);

            $this->assertSame('visit_briefing', $plan->questionType, $question);
            $this->assertFalse($plan->refused(), $question);
            $this->assertSame(ChartQuestionPlanner::defaultSections(), $plan->sections, $question);
            $this->assertSame([], $plan->skippedSections, $question);
            $this->assertNull($plan->refusal, $question);
        }
    }

    public function testAmbiguousFollowUpUsesPriorQuestionTypeAsPlannerHint(): void
    {
        $summary = new ConversationTurnSummary('lab', ['lab:procedure_result/agentforge-a1c-2026-04@2026-04-10']);
        $plan = (new ChartQuestionPlanner())->plan(new AgentQuestion('What about those?'), 8000, $summary);

        $this->assertSame('lab', $plan->questionType);
        $this->assertSame([ChartQuestionPlanner::SECTION_LABS], $plan->sections);
    }

    public function testRoutesProblemAndExtendedKeywordsToMinimumEvidenceSections(): void
    {
        $planner = new ChartQuestionPlanner();

        $problemPlan = $planner->plan(new AgentQuestion('What problems are documented?'), 8000);
        $this->assertSame('problem', $problemPlan->questionType);
        $this->assertSame(
            [ChartQuestionPlanner::SECTION_DEMOGRAPHICS, ChartQuestionPlanner::SECTION_PROBLEMS],
            $problemPlan->sections,
        );

        $comorbidPlan = $planner->plan(new AgentQuestion('Any comorbidities to be aware of?'), 8000);
        $this->assertSame('problem', $comorbidPlan->questionType);
        $this->assertSame(
            [ChartQuestionPlanner::SECTION_DEMOGRAPHICS, ChartQuestionPlanner::SECTION_PROBLEMS],
            $comorbidPlan->sections,
        );

        foreach (
            [
                'Show me the recent sodium.',
                'What is the latest creatinine?',
                'Cholesterol panel result?',
                'Latest hemoglobin value.',
            ] as $labQuestion
        ) {
            $labPlan = $planner->plan(new AgentQuestion($labQuestion), 8000);
            $this->assertSame('lab', $labPlan->questionType, $labQuestion);
            $this->assertSame([ChartQuestionPlanner::SECTION_LABS], $labPlan->sections, $labQuestion);
        }

        foreach (
            [
                'What was the last visit about?',
                'Summarize the previous visit.',
                'Give me the history of present illness.',
                'Read the HPI from the last note.',
            ] as $noteQuestion
        ) {
            $notePlan = $planner->plan(new AgentQuestion($noteQuestion), 8000);
            $this->assertSame('last_plan', $notePlan->questionType, $noteQuestion);
            $this->assertSame([ChartQuestionPlanner::SECTION_NOTES], $notePlan->sections, $noteQuestion);
        }
    }
}
