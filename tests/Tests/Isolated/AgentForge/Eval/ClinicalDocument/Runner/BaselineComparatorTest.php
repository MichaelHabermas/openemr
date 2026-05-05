<?php

declare(strict_types=1);

namespace OpenEMR\Tests\Isolated\AgentForge\Eval\ClinicalDocument\Runner;

use OpenEMR\AgentForge\Eval\ClinicalDocument\Runner\BaselineComparator;
use OpenEMR\AgentForge\Eval\ClinicalDocument\Runner\EvalRunResult;
use OpenEMR\AgentForge\Eval\ClinicalDocument\Runner\RegressionVerdict;
use OpenEMR\AgentForge\Eval\ClinicalDocument\Runner\RubricSummary;
use PHPUnit\Framework\TestCase;

final class BaselineComparatorTest extends TestCase
{
    public function testThresholdViolationWhenPassRateBelowThreshold(): void
    {
        $result = new EvalRunResult([], [
            'schema_valid' => new RubricSummary('schema_valid', 0, 1, 0, 0.0),
        ]);

        $verdict = (new BaselineComparator())->compare(
            $result,
            ['rubric_thresholds' => ['schema_valid' => 1.0], 'regression_max_drop_pct' => 5],
            ['rubric_pass_rates' => ['schema_valid' => 0.0]],
        );

        $this->assertSame(RegressionVerdict::ThresholdViolation, $verdict);
    }
}
