<?php

/**
 * Isolated tests for AgentForge clinical document eval support.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

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

    public function testThresholdViolationWhenCaseCountDropsBelowBaseline(): void
    {
        $result = new EvalRunResult([['case_id' => 'only-one']], [
            'schema_valid' => new RubricSummary('schema_valid', 1, 0, 0, 1.0),
        ]);

        $verdict = (new BaselineComparator())->compare(
            $result,
            ['rubric_thresholds' => ['schema_valid' => 1.0], 'regression_max_drop_pct' => 5],
            ['case_count' => 2, 'rubric_pass_rates' => ['schema_valid' => 1.0]],
        );

        $this->assertSame(RegressionVerdict::ThresholdViolation, $verdict);
    }

    public function testRegressionExceededWhenDropIsGreaterThanConfiguredTolerance(): void
    {
        $result = new EvalRunResult([], [
            'citation_present' => new RubricSummary('citation_present', 94, 6, 0, 0.94),
        ]);

        $verdict = (new BaselineComparator())->compare(
            $result,
            ['rubric_thresholds' => ['citation_present' => 0.0], 'regression_max_drop_pct' => 5],
            ['rubric_pass_rates' => ['citation_present' => 1.0]],
        );

        $this->assertSame(RegressionVerdict::RegressionExceeded, $verdict);
    }

    public function testBaselineMetWhenDropEqualsConfiguredTolerance(): void
    {
        $result = new EvalRunResult([], [
            'safe_refusal' => new RubricSummary('safe_refusal', 95, 5, 0, 0.95),
        ]);

        $verdict = (new BaselineComparator())->compare(
            $result,
            ['rubric_thresholds' => ['safe_refusal' => 0.0], 'regression_max_drop_pct' => 5],
            ['rubric_pass_rates' => ['safe_refusal' => 1.0]],
        );

        $this->assertSame(RegressionVerdict::BaselineMet, $verdict);
    }

    public function testThresholdViolationWhenBelowThresholdButWithinRegressionTolerance(): void
    {
        $result = new EvalRunResult([], [
            'no_phi_in_logs' => new RubricSummary('no_phi_in_logs', 96, 4, 0, 0.96),
        ]);

        $verdict = (new BaselineComparator())->compare(
            $result,
            ['rubric_thresholds' => ['no_phi_in_logs' => 0.97], 'regression_max_drop_pct' => 5],
            ['rubric_pass_rates' => ['no_phi_in_logs' => 1.0]],
        );

        $this->assertSame(RegressionVerdict::ThresholdViolation, $verdict);
    }

    public function testThresholdViolationWhenProtectedRubricIsMissingFromResult(): void
    {
        $result = new EvalRunResult([], [
            'schema_valid' => new RubricSummary('schema_valid', 1, 0, 0, 1.0),
        ]);

        $verdict = (new BaselineComparator())->compare(
            $result,
            ['rubric_thresholds' => ['schema_valid' => 1.0, 'citation_present' => 1.0], 'regression_max_drop_pct' => 5],
            ['rubric_pass_rates' => ['schema_valid' => 1.0, 'citation_present' => 1.0]],
        );

        $this->assertSame(RegressionVerdict::ThresholdViolation, $verdict);
    }

    public function testThresholdViolationWhenProtectedRubricHasNoApplicableCases(): void
    {
        $result = new EvalRunResult([], [
            'citation_present' => new RubricSummary('citation_present', 0, 0, 10, 0.0),
        ]);

        $verdict = (new BaselineComparator())->compare(
            $result,
            ['rubric_thresholds' => ['citation_present' => 1.0], 'regression_max_drop_pct' => 5],
            ['rubric_pass_rates' => ['citation_present' => 1.0]],
        );

        $this->assertSame(RegressionVerdict::ThresholdViolation, $verdict);
    }
}
