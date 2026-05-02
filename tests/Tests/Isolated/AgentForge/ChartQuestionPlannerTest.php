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
            [ChartQuestionPlanner::SECTION_MEDICATIONS],
            $planner->plan(new AgentQuestion('What medications are active?'), 8000)->sections,
        );
        $this->assertSame(
            [ChartQuestionPlanner::SECTION_MEDICATIONS],
            $planner->plan(new AgentQuestion('List current prescriptions.'), 8000)->sections,
        );
        $this->assertSame(
            [ChartQuestionPlanner::SECTION_NOTES],
            $planner->plan(new AgentQuestion('What was the last plan?'), 8000)->sections,
        );
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
                ChartQuestionPlanner::SECTION_PROBLEMS,
                ChartQuestionPlanner::SECTION_MEDICATIONS,
                ChartQuestionPlanner::SECTION_NOTES,
            ],
            $plan->skippedSections,
        );
    }

    public function testAmbiguousChartQuestionUsesConservativeMinimalRoute(): void
    {
        $plan = (new ChartQuestionPlanner())->plan(new AgentQuestion('What should I know?'), 8000);

        $this->assertSame('ambiguous_question', $plan->questionType);
        $this->assertTrue($plan->refused());
        $this->assertSame([], $plan->sections);
        $this->assertSame(ChartQuestionPlanner::defaultSections(), $plan->skippedSections);
        $this->assertStringContainsString('specific chart question', (string) $plan->refusal);
    }
}
