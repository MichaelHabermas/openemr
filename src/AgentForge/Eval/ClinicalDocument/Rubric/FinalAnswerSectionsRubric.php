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

final class FinalAnswerSectionsRubric implements Rubric
{
    public function name(): string
    {
        return 'final_answer_sections';
    }

    public function evaluate(RubricInputs $inputs): RubricResult
    {
        if ($inputs->case->expectedRubrics->expectedFor($this->name()) === null) {
            return new RubricResult($this->name(), RubricStatus::NotApplicable, 'Final answer section checks are not required.');
        }

        $requiredSections = $inputs->case->expectedAnswer->requiredSections;
        if ($requiredSections === []) {
            return new RubricResult($this->name(), RubricStatus::Pass, 'No final answer sections were required.');
        }

        $sections = $inputs->output->answer['sections'] ?? null;
        if (!is_array($sections)) {
            return new RubricResult($this->name(), RubricStatus::Fail, 'Final answer sections were not reported.');
        }

        $sectionNames = array_values(array_filter($sections, is_string(...)));
        foreach ($requiredSections as $requiredSection) {
            if (!in_array($requiredSection, $sectionNames, true)) {
                return new RubricResult($this->name(), RubricStatus::Fail, sprintf('Final answer is missing required section "%s".', $requiredSection));
            }
        }

        return new RubricResult($this->name(), RubricStatus::Pass, 'Final answer includes all required sections.');
    }
}
