<?php

/**
 * Fixture selector used by deterministic evals to prove selector-driven routing.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\AgentForge\Eval;

use OpenEMR\AgentForge\Evidence\ChartQuestionPlanner;
use OpenEMR\AgentForge\Evidence\ToolSelectionException;
use OpenEMR\AgentForge\Evidence\ToolSelectionProvider;
use OpenEMR\AgentForge\Evidence\ToolSelectionRequest;
use OpenEMR\AgentForge\Evidence\ToolSelectionResult;

final readonly class EvalToolSelectionProvider implements ToolSelectionProvider
{
    public function select(ToolSelectionRequest $request): ToolSelectionResult
    {
        $question = strtolower($request->question->value);

        if (str_contains($question, 'anything changed') || str_contains($question, 'changed since')) {
            return new ToolSelectionResult('follow_up_change_review', [
                ChartQuestionPlanner::SECTION_LABS,
                ChartQuestionPlanner::SECTION_VITALS,
                ChartQuestionPlanner::SECTION_NOTES,
            ]);
        }

        if (str_contains($question, 'double-check before prescribing') || str_contains($question, 'double check before prescribing')) {
            return new ToolSelectionResult('pre_prescribing_chart_check', [
                ChartQuestionPlanner::SECTION_MEDICATIONS,
                ChartQuestionPlanner::SECTION_INACTIVE_MEDICATIONS,
                ChartQuestionPlanner::SECTION_ALLERGIES,
                ChartQuestionPlanner::SECTION_LABS,
                ChartQuestionPlanner::SECTION_VITALS,
            ]);
        }

        if (str_contains($question, 'what about those') && $request->conversationSummary !== null) {
            return new ToolSelectionResult($request->conversationSummary->questionType, [
                ChartQuestionPlanner::SECTION_LABS,
            ]);
        }

        throw new ToolSelectionException('Eval selector has no fixture selection for this question.');
    }

    public function mode(): string
    {
        return 'fixture_selector';
    }
}
