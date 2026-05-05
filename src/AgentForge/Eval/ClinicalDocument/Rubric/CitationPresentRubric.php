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

final readonly class CitationPresentRubric implements Rubric
{
    public function __construct(private CitationShape $citationShape = new CitationShape())
    {
    }

    public function name(): string
    {
        return 'citation_present';
    }

    public function evaluate(RubricInputs $inputs): RubricResult
    {
        if ($inputs->case->expectedRubrics->expectedFor($this->name()) === null) {
            return new RubricResult($this->name(), RubricStatus::NotApplicable, 'Citations are not required for this case.');
        }

        $facts = $inputs->output->extraction['facts'] ?? [];
        if (!is_array($facts) || $facts === []) {
            return new RubricResult($this->name(), RubricStatus::Fail, 'No extracted facts were available to cite.');
        }

        foreach ($facts as $fact) {
            if (!is_array($fact) || !$this->citationShape->factHasValidCitation(StringKeyedArray::filter($fact))) {
                return new RubricResult($this->name(), RubricStatus::Fail, 'At least one extracted fact is missing valid citation metadata.');
            }
        }

        return new RubricResult($this->name(), RubricStatus::Pass, 'Every extracted fact has citation metadata.');
    }
}
