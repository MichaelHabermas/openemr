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
use OpenEMR\AgentForge\Eval\ClinicalDocument\Rubric\FactuallyConsistentRubric;
use OpenEMR\AgentForge\Eval\ClinicalDocument\Rubric\RubricStatus;

final class FactuallyConsistentRubricTest extends RubricTestCase
{
    public function testMatchesExpectedValueContains(): void
    {
        $result = (new FactuallyConsistentRubric())->evaluate($this->inputs(
            ['factually_consistent' => true],
            new CaseRunOutput('ok', ['facts' => [[
                'field_path' => 'results[0]',
                'test_name' => 'LDL Cholesterol',
                'value' => '148 mg/dL',
                'confidence' => 0.98,
            ]]]),
            [['field_path' => 'results[0]', 'test_name' => 'LDL Cholesterol', 'value_contains' => '148', 'confidence_min' => 0.95]],
        ));

        $this->assertSame(RubricStatus::Pass, $result->status);
    }

    public function testRejectsWrongFieldPath(): void
    {
        $result = (new FactuallyConsistentRubric())->evaluate($this->inputs(
            ['factually_consistent' => true],
            new CaseRunOutput('ok', ['facts' => [[
                'field_path' => 'results[1]',
                'test_name' => 'LDL Cholesterol',
                'value' => '148 mg/dL',
                'confidence' => 0.98,
            ]]]),
            [['field_path' => 'results[0]', 'test_name' => 'LDL Cholesterol', 'value_contains' => '148']],
        ));

        $this->assertSame(RubricStatus::Fail, $result->status);
    }

    public function testRejectsLowConfidence(): void
    {
        $result = (new FactuallyConsistentRubric())->evaluate($this->inputs(
            ['factually_consistent' => true],
            new CaseRunOutput('ok', ['facts' => [[
                'field_path' => 'results[0]',
                'test_name' => 'LDL Cholesterol',
                'value' => '148 mg/dL',
                'confidence' => 0.70,
            ]]]),
            [['field_path' => 'results[0]', 'test_name' => 'LDL Cholesterol', 'confidence_min' => 0.95]],
        ));

        $this->assertSame(RubricStatus::Fail, $result->status);
    }
}
