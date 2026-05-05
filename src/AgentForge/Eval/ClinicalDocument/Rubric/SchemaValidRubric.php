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

final class SchemaValidRubric implements Rubric
{
    public function name(): string
    {
        return 'schema_valid';
    }

    public function evaluate(RubricInputs $inputs): RubricResult
    {
        if ($inputs->case->expectedRubrics->expectedFor($this->name()) === null) {
            return new RubricResult($this->name(), RubricStatus::NotApplicable, 'Schema validity is not required for this case.');
        }

        if (($inputs->output->extraction['schema_valid'] ?? false) === true) {
            return new RubricResult($this->name(), RubricStatus::Pass, 'Extraction reported schema_valid=true.');
        }

        return new RubricResult($this->name(), RubricStatus::Fail, $inputs->output->failureReason ?? 'Extraction did not report schema_valid=true.');
    }
}
