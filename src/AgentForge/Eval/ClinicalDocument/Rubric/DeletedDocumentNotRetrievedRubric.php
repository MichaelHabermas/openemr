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

final class DeletedDocumentNotRetrievedRubric implements Rubric
{
    public function name(): string
    {
        return 'deleted_document_not_retrieved';
    }

    public function evaluate(RubricInputs $inputs): RubricResult
    {
        if ($inputs->case->expectedRubrics->expectedFor($this->name()) === null) {
            return new RubricResult($this->name(), RubricStatus::NotApplicable, 'Deleted/retracted document retrieval is not required for this case.');
        }

        if (($inputs->output->retrieval['returned_retracted_document'] ?? false) === true) {
            return new RubricResult($this->name(), RubricStatus::Fail, 'Retrieval returned a retracted or deleted document.');
        }
        if (($inputs->output->retrieval['retraction_exclusion_checked'] ?? false) !== true) {
            return new RubricResult($this->name(), RubricStatus::Fail, 'Retrieval did not report a retraction exclusion check.');
        }

        return new RubricResult($this->name(), RubricStatus::Pass, 'Retraction exclusion was checked and no retracted or deleted document was returned.');
    }
}
