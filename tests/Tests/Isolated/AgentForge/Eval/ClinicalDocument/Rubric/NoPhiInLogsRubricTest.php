<?php

declare(strict_types=1);

namespace OpenEMR\Tests\Isolated\AgentForge\Eval\ClinicalDocument\Rubric;

use OpenEMR\AgentForge\Eval\ClinicalDocument\Adapter\CaseRunOutput;
use OpenEMR\AgentForge\Eval\ClinicalDocument\Rubric\NoPhiInLogsRubric;
use OpenEMR\AgentForge\Eval\ClinicalDocument\Rubric\RubricStatus;

final class NoPhiInLogsRubricTest extends RubricTestCase
{
    public function testFailsForbiddenKey(): void
    {
        $result = (new NoPhiInLogsRubric())->evaluate($this->inputs(
            ['no_phi_in_logs' => true],
            new CaseRunOutput('ok', logLines: [['patient_name' => 'Alice Chen']]),
            logMustNotContain: ['Alice Chen'],
        ));

        $this->assertSame(RubricStatus::Fail, $result->status);
    }
}
