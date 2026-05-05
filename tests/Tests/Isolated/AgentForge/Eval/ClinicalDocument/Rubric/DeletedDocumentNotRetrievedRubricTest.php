<?php

/**
 * Isolated tests for AgentForge clinical document eval support.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Tests\Isolated\AgentForge\Eval\ClinicalDocument\Rubric;

use OpenEMR\AgentForge\Eval\ClinicalDocument\Adapter\CaseRunOutput;
use OpenEMR\AgentForge\Eval\ClinicalDocument\Rubric\DeletedDocumentNotRetrievedRubric;
use OpenEMR\AgentForge\Eval\ClinicalDocument\Rubric\RubricStatus;

final class DeletedDocumentNotRetrievedRubricTest extends RubricTestCase
{
    public function testFailsWhenRetractedDocumentReturned(): void
    {
        $result = (new DeletedDocumentNotRetrievedRubric())->evaluate($this->inputs(
            ['deleted_document_not_retrieved' => true],
            new CaseRunOutput('ok', retrieval: ['returned_retracted_document' => true]),
        ));

        $this->assertSame(RubricStatus::Fail, $result->status);
    }
}
