<?php

declare(strict_types=1);

namespace OpenEMR\Tests\Isolated\AgentForge\Eval\ClinicalDocument\Rubric;

use OpenEMR\AgentForge\Eval\ClinicalDocument\Adapter\CaseRunOutput;
use OpenEMR\AgentForge\Eval\ClinicalDocument\Rubric\RubricStatus;
use OpenEMR\AgentForge\Eval\ClinicalDocument\Rubric\SchemaValidRubric;

final class SchemaValidRubricTest extends RubricTestCase
{
    public function testPassesWhenSchemaValidIsTrue(): void
    {
        $result = (new SchemaValidRubric())->evaluate($this->inputs(
            ['schema_valid' => true],
            new CaseRunOutput('ok', ['schema_valid' => true]),
        ));

        $this->assertSame(RubricStatus::Pass, $result->status);
    }
}
