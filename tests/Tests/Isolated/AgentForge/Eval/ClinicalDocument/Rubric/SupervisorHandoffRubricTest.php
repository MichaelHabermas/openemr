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
use OpenEMR\AgentForge\Eval\ClinicalDocument\Case\ExpectedAnswer;
use OpenEMR\AgentForge\Eval\ClinicalDocument\Rubric\RubricStatus;
use OpenEMR\AgentForge\Eval\ClinicalDocument\Rubric\SupervisorHandoffRubric;

final class SupervisorHandoffRubricTest extends RubricTestCase
{
    public function testPassesStructuredRequiredHandoff(): void
    {
        $result = (new SupervisorHandoffRubric())->evaluate($this->inputs(
            ['supervisor_handoff' => true],
            new CaseRunOutput('ok', answer: ['handoffs' => [[
                'source_node' => 'supervisor',
                'destination_node' => 'evidence-retriever',
                'decision_reason' => 'guideline_evidence_required',
                'task_type' => 'clinician_review',
                'outcome' => 'handoff',
                'latency_ms' => 3,
                'error_reason' => null,
            ]]]),
            expectedAnswer: new ExpectedAnswer(requiredHandoffTypes: ['clinician_review']),
        ));

        $this->assertSame(RubricStatus::Pass, $result->status);
    }

    public function testFailsUnstructuredHandoff(): void
    {
        $result = (new SupervisorHandoffRubric())->evaluate($this->inputs(
            ['supervisor_handoff' => true],
            new CaseRunOutput('ok', answer: ['handoffs' => ['please review']]),
            expectedAnswer: new ExpectedAnswer(requiredHandoffTypes: ['clinician_review']),
        ));

        $this->assertSame(RubricStatus::Fail, $result->status);
    }
}
