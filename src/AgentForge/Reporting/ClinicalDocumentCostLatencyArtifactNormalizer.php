<?php

/**
 * Normalize AgentForge proof artifacts for the clinical document cost/latency report.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\AgentForge\Reporting;

use OpenEMR\AgentForge\StringKeyedArray;
use RuntimeException;

final class ClinicalDocumentCostLatencyArtifactNormalizer
{
    public function normalize(
        string $clinicalRunFile,
        string $clinicalSummaryFile,
        ?string $tier2File = null,
        ?string $deployedSmokeFile = null,
    ): ClinicalDocumentCostLatencyRun {
        $clinicalRun = $this->readJsonFile($clinicalRunFile, required: true);
        $clinicalSummary = $this->readJsonFile($clinicalSummaryFile, required: true);
        $tier2 = $tier2File === null ? [] : $this->readJsonFile($tier2File, required: false);
        $deployedSmoke = $deployedSmokeFile === null ? [] : $this->readJsonFile($deployedSmokeFile, required: false);

        $clinicalHandoffLatencies = $this->clinicalHandoffLatencies($clinicalRun);
        $tier2Latencies = $this->latenciesFromRows($tier2['results'] ?? []);
        $deployedSmokeLatencies = $this->latenciesFromRows($deployedSmoke['cases'] ?? []);
        $stageTimings = $this->stageTimings($tier2['results'] ?? []);

        return new ClinicalDocumentCostLatencyRun(
            clinicalExecutedAt: $this->stringValue($clinicalSummary['executed_at_utc'] ?? $clinicalRun['executed_at_utc'] ?? 'unknown'),
            clinicalVerdict: $this->stringValue($clinicalSummary['verdict'] ?? 'unknown'),
            clinicalCaseCount: $this->intValue($clinicalSummary['case_count'] ?? 0),
            clinicalSummary: $clinicalSummary,
            clinicalHandoffLatenciesMs: $clinicalHandoffLatencies,
            tier2EstimatedCostUsd: $this->nullableFloat($tier2['aggregate_estimated_cost_usd'] ?? null),
            tier2InputTokens: $this->nullableInt($tier2['aggregate_input_tokens'] ?? null),
            tier2OutputTokens: $this->nullableInt($tier2['aggregate_output_tokens'] ?? null),
            tier2ProviderModel: isset($tier2['provider_model']) ? $this->stringValue($tier2['provider_model']) : null,
            tier2LatenciesMs: $tier2Latencies,
            deployedSmokeLatenciesMs: $deployedSmokeLatencies,
            stageTimingsMs: $stageTimings,
            evidencePaths: array_values(array_filter([$clinicalRunFile, $clinicalSummaryFile, $tier2File, $deployedSmokeFile])),
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function readJsonFile(string $file, bool $required): array
    {
        if (!is_file($file)) {
            if (!$required) {
                return [];
            }
            throw new RuntimeException(sprintf('Required report artifact is missing: %s', $file));
        }

        $json = file_get_contents($file);
        if ($json === false) {
            throw new RuntimeException(sprintf('Unable to read report artifact: %s', $file));
        }

        $data = json_decode($json, true);
        if (!is_array($data)) {
            throw new RuntimeException(sprintf('Invalid JSON report artifact %s: %s', $file, json_last_error_msg()));
        }

        return StringKeyedArray::filter($data);
    }

    /**
     * @param array<string, mixed> $clinicalRun
     * @return list<int>
     */
    private function clinicalHandoffLatencies(array $clinicalRun): array
    {
        $latencies = [];
        $cases = $clinicalRun['cases'] ?? [];
        if (!is_array($cases)) {
            return [];
        }

        foreach ($cases as $case) {
            if (!is_array($case) || !is_array($case['answer_handoffs'] ?? null)) {
                continue;
            }
            foreach ($case['answer_handoffs'] as $handoff) {
                if (is_array($handoff) && is_numeric($handoff['latency_ms'] ?? null)) {
                    $latencies[] = max(0, (int) $handoff['latency_ms']);
                }
            }
        }

        return $latencies;
    }

    /**
     * @param mixed $rows
     * @return list<int>
     */
    private function latenciesFromRows(mixed $rows): array
    {
        if (!is_array($rows)) {
            return [];
        }

        $latencies = [];
        foreach ($rows as $row) {
            if (is_array($row) && is_numeric($row['latency_ms'] ?? null)) {
                $latencies[] = max(0, (int) $row['latency_ms']);
            }
        }

        return $latencies;
    }

    /**
     * @param mixed $rows
     * @return array<string, int>
     */
    private function stageTimings(mixed $rows): array
    {
        if (!is_array($rows)) {
            return [];
        }

        $timings = [];
        foreach ($rows as $row) {
            if (!is_array($row) || !is_array($row['log_context'] ?? null)) {
                continue;
            }
            $logContext = $row['log_context'];
            if (!is_array($logContext['stage_timings_ms'] ?? null)) {
                continue;
            }
            foreach ($logContext['stage_timings_ms'] as $stage => $latency) {
                if (is_string($stage) && is_numeric($latency)) {
                    $timings[$stage] = ($timings[$stage] ?? 0) + max(0, (int) $latency);
                }
            }
        }
        arsort($timings);

        return $timings;
    }

    private function stringValue(mixed $value): string
    {
        return is_scalar($value) ? (string) $value : 'unknown';
    }

    private function intValue(mixed $value): int
    {
        return is_numeric($value) ? (int) $value : 0;
    }

    private function nullableInt(mixed $value): ?int
    {
        return is_numeric($value) ? (int) $value : null;
    }

    private function nullableFloat(mixed $value): ?float
    {
        return is_numeric($value) ? (float) $value : null;
    }
}
