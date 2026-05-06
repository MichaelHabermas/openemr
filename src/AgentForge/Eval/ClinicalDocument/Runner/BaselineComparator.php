<?php

/**
 * Clinical document eval support for AgentForge.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\AgentForge\Eval\ClinicalDocument\Runner;

final class BaselineComparator
{
    /**
     * @param array<string, mixed> $thresholds
     * @param array<string, mixed> $baseline
     */
    public function compare(EvalRunResult $result, array $thresholds, array $baseline): RegressionVerdict
    {
        $rubricThresholds = is_array($thresholds['rubric_thresholds'] ?? null) ? $thresholds['rubric_thresholds'] : [];
        $baselineRates = is_array($baseline['rubric_pass_rates'] ?? null) ? $baseline['rubric_pass_rates'] : [];
        $maxDropValue = $thresholds['regression_max_drop_pct'] ?? 5;
        $maxDrop = (is_numeric($maxDropValue) ? (float) (string) $maxDropValue : 5.0) / 100.0;
        $hasThresholdViolation = false;
        $baselineCaseCount = $baseline['case_count'] ?? null;
        if (is_numeric($baselineCaseCount) && count($result->caseResults) < (int) $baselineCaseCount) {
            return RegressionVerdict::ThresholdViolation;
        }

        foreach (array_unique(array_merge(array_keys($rubricThresholds), array_keys($baselineRates))) as $name) {
            if (!isset($result->rubricSummaries[$name])) {
                return RegressionVerdict::ThresholdViolation;
            }

            $thresholdValue = $rubricThresholds[$name] ?? 0.0;
            $threshold = is_numeric($thresholdValue) ? (float) (string) $thresholdValue : 0.0;
            $summary = $result->rubricSummaries[$name];
            if ($threshold > 0.0 && ($summary->passed + $summary->failed) === 0) {
                return RegressionVerdict::ThresholdViolation;
            }
        }

        foreach ($result->rubricSummaries as $name => $summary) {
            $thresholdValue = $rubricThresholds[$name] ?? 0.0;
            $threshold = is_numeric($thresholdValue) ? (float) (string) $thresholdValue : 0.0;
            if ($summary->passRate < $threshold) {
                $hasThresholdViolation = true;
            }

            $baselineValue = $baselineRates[$name] ?? 0.0;
            $baselineRate = is_numeric($baselineValue) ? (float) (string) $baselineValue : 0.0;
            if (($baselineRate - $summary->passRate) - $maxDrop > 0.000000001) {
                return RegressionVerdict::RegressionExceeded;
            }
        }

        return $hasThresholdViolation ? RegressionVerdict::ThresholdViolation : RegressionVerdict::BaselineMet;
    }
}
