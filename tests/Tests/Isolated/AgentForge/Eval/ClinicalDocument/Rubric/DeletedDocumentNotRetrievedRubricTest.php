<?php

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
