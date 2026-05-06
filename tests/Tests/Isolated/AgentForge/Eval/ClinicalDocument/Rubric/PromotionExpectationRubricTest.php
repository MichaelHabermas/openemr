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
use OpenEMR\AgentForge\Eval\ClinicalDocument\Rubric\PromotionExpectationRubric;
use OpenEMR\AgentForge\Eval\ClinicalDocument\Rubric\RubricStatus;

final class PromotionExpectationRubricTest extends RubricTestCase
{
    public function testFailsWhenExpectedPromotionIsMissing(): void
    {
        $result = (new PromotionExpectationRubric())->evaluate($this->inputs(
            ['promotion_expectations' => true],
            new CaseRunOutput('ok', promotions: []),
            expectedPromotions: [['table' => 'procedure_result', 'value_contains' => '158']],
        ));

        $this->assertSame(RubricStatus::Fail, $result->status);
    }

    public function testFailsWhenPromotionLacksCitationOrFingerprint(): void
    {
        $result = (new PromotionExpectationRubric())->evaluate($this->inputs(
            ['promotion_expectations' => true],
            new CaseRunOutput('ok', promotions: [[
                'table' => 'procedure_result',
                'value' => '158',
                'citation' => [],
                'fact_fingerprint' => '',
            ]]),
            expectedPromotions: [['table' => 'procedure_result', 'value_contains' => '158']],
        ));

        $this->assertSame(RubricStatus::Fail, $result->status);
    }

    public function testPassesWhenExpectedPromotionIsCitedAndFingerprinted(): void
    {
        $result = (new PromotionExpectationRubric())->evaluate($this->inputs(
            ['promotion_expectations' => true],
            new CaseRunOutput('ok', promotions: [[
                'table' => 'procedure_result',
                'value' => '158',
                'citation' => ['quote_or_value' => 'LDL Cholesterol 158 mg/dL'],
                'fact_fingerprint' => 'sha256:abc',
            ]]),
            expectedPromotions: [['table' => 'procedure_result', 'value_contains' => '158']],
        ));

        $this->assertSame(RubricStatus::Pass, $result->status);
    }
}
