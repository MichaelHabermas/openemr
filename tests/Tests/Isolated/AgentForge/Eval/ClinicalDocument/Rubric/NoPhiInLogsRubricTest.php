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
