<?php

/**
 * Clinical document eval support for AgentForge.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\AgentForge\Eval\ClinicalDocument\Rubric;

use OpenEMR\AgentForge\StringKeyedArray;

final class AnswerCitationCoverageRubric implements Rubric
{
    public function name(): string
    {
        return 'answer_citation_coverage';
    }

    public function evaluate(RubricInputs $inputs): RubricResult
    {
        if ($inputs->case->expectedRubrics->expectedFor($this->name()) === null) {
            return new RubricResult($this->name(), RubricStatus::NotApplicable, 'Answer citation coverage is not required.');
        }

        $coverage = $inputs->output->answer['citation_coverage'] ?? null;
        if (!is_array($coverage)) {
            return new RubricResult($this->name(), RubricStatus::Fail, 'Answer citation coverage was not reported.');
        }
        $coverage = StringKeyedArray::filter($coverage);

        if ($inputs->case->expectedAnswer->everyPatientClaimHasCitation && !$this->claimGroupCovered($coverage, 'patient_claims')) {
            return new RubricResult($this->name(), RubricStatus::Fail, 'At least one patient claim lacks a citation.');
        }

        if ($inputs->case->expectedAnswer->everyGuidelineClaimHasCitation && !$this->claimGroupCovered($coverage, 'guideline_claims')) {
            return new RubricResult($this->name(), RubricStatus::Fail, 'At least one guideline claim lacks a citation.');
        }

        return new RubricResult($this->name(), RubricStatus::Pass, 'Answer-level citation coverage is complete.');
    }

    /** @param array<string, mixed> $coverage */
    private function claimGroupCovered(array $coverage, string $group): bool
    {
        $counts = $coverage[$group] ?? null;
        if (!is_array($counts)) {
            return false;
        }

        $total = $counts['total'] ?? null;
        $cited = $counts['cited'] ?? null;

        return is_int($total) && is_int($cited) && $total > 0 && $total === $cited;
    }
}
