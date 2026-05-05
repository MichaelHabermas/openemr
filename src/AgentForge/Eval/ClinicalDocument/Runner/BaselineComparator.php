<?php

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

        foreach ($result->rubricSummaries as $name => $summary) {
            $thresholdValue = $rubricThresholds[$name] ?? 0.0;
            $threshold = is_numeric($thresholdValue) ? (float) (string) $thresholdValue : 0.0;
            if ($summary->passRate < $threshold) {
                $hasThresholdViolation = true;
            }

            $baselineValue = $baselineRates[$name] ?? 0.0;
            $baselineRate = is_numeric($baselineValue) ? (float) (string) $baselineValue : 0.0;
            if (($baselineRate - $summary->passRate) > $maxDrop) {
                return RegressionVerdict::RegressionExceeded;
            }
        }

        return $hasThresholdViolation ? RegressionVerdict::ThresholdViolation : RegressionVerdict::BaselineMet;
    }
}
