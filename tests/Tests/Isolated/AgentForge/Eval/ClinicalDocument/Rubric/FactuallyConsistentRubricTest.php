<?php

declare(strict_types=1);

namespace OpenEMR\Tests\Isolated\AgentForge\Eval\ClinicalDocument\Rubric;

use OpenEMR\AgentForge\Eval\ClinicalDocument\Adapter\CaseRunOutput;
use OpenEMR\AgentForge\Eval\ClinicalDocument\Rubric\FactuallyConsistentRubric;
use OpenEMR\AgentForge\Eval\ClinicalDocument\Rubric\RubricStatus;

final class FactuallyConsistentRubricTest extends RubricTestCase
{
    public function testMatchesExpectedValueContains(): void
    {
        $result = (new FactuallyConsistentRubric())->evaluate($this->inputs(
            ['factually_consistent' => true],
            new CaseRunOutput('ok', ['facts' => [['test_name' => 'LDL Cholesterol', 'value' => '148 mg/dL']]]),
            [['test_name' => 'LDL Cholesterol', 'value_contains' => '148']],
        ));

        $this->assertSame(RubricStatus::Pass, $result->status);
    }
}
