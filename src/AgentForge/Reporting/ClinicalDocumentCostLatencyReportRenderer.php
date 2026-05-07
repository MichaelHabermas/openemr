<?php

/**
 * Markdown renderer for the Week 2 clinical document cost/latency report.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\AgentForge\Reporting;

final class ClinicalDocumentCostLatencyReportRenderer
{
    public function render(ClinicalDocumentCostLatencyRun $run): string
    {
        $clinicalP50 = $run->clinicalLatencyPlaceholder() ? 'placeholder 0 ms' : $this->formatLatency($this->percentile($run->clinicalHandoffLatenciesMs, 50));
        $clinicalP95 = $run->clinicalLatencyPlaceholder() ? 'placeholder 0 ms' : $this->formatLatency($this->percentile($run->clinicalHandoffLatenciesMs, 95));
        $tier2P50 = $this->formatLatency($this->percentile($run->tier2LatenciesMs, 50));
        $tier2P95 = $this->formatLatency($this->percentile($run->tier2LatenciesMs, 95));
        $smokeP50 = $this->formatLatency($this->percentile($run->deployedSmokeLatenciesMs, 50));
        $smokeP95 = $this->formatLatency($this->percentile($run->deployedSmokeLatenciesMs, 95));

        return rtrim(sprintf(
            "# Week 2 Clinical Document Cost And Latency\n\n"
            . "**Updated:** %s\n"
            . "**Scope:** Week 2 clinical-document path: strict document extraction, guideline retrieval, supervisor handoffs, no-PHI logging, and deterministic eval artifacts.\n"
            . "**Status:** Reproducible report rendered from AgentForge proof artifacts. Values are labeled as measured, projected, placeholder, or unknown.\n\n"
            . "## Executive Summary\n\n"
            . "The latest clinical-document gate artifact contains `%d` cases with verdict `%s`. Clinical-document handoff latency is %s: the field is instrumented, but the current deterministic fixture artifact does not prove runtime latency. Clinical-document model cost remains `unknown/not recorded` unless a live clinical-document artifact provides provider token usage.\n\n"
            . "The available live-provider development spend baseline is %s using `%s` with `%s` input tokens and `%s` output tokens. Shared live request latency p50/p95 is `%s` / `%s`; deployed smoke p50/p95 is `%s` / `%s`.\n\n"
            . "## Evidence Used\n\n%s\n\n"
            . "## Current Metrics\n\n"
            . "| Metric | Value | Interpretation |\n"
            . "| --- | ---: | --- |\n"
            . "| Clinical run executed at | `%s` | Source clinical-document summary timestamp. |\n"
            . "| Clinical cases | `%d` | Deterministic Week 2 gate cases. |\n"
            . "| Clinical verdict | `%s` | Baseline/threshold result from the gate. |\n"
            . "| Clinical handoff p50 | `%s` | %s |\n"
            . "| Clinical handoff p95 | `%s` | %s |\n"
            . "| Tier 2 live p50 | `%s` | Measured shared live-provider request latency. |\n"
            . "| Tier 2 live p95 | `%s` | Measured shared live-provider request latency. |\n"
            . "| Deployed smoke p50 | `%s` | Measured deployed smoke request latency. |\n"
            . "| Deployed smoke p95 | `%s` | Measured deployed smoke request latency. |\n"
            . "| Actual available provider spend | `%s` | From available Tier 2 live artifact; clinical-document spend is unknown if not present in artifacts. |\n\n"
            . "## Projected Production Cost Drivers\n\n"
            . "- Model calls for extraction, embeddings, reranking, and final draft generation.\n"
            . "- Document storage and retention for original uploads and source-review metadata.\n"
            . "- MariaDB vector index writes and query work for guideline and document evidence boundaries.\n"
            . "- Human-review operations for identity mismatch, uncertain facts, duplicate handling, and retractions.\n"
            . "- Audit retention, backup, monitoring, incident response, and vendor/compliance review.\n\n"
            . "## Bottleneck Analysis\n\n%s\n\n"
            . "## Acceptance Matrix\n\n"
            . "| Requirement | Status | Proof |\n"
            . "| --- | --- | --- |\n"
            . "| Cost/latency report exists. | Implemented | This file is rendered by `agent-forge/scripts/render-clinical-document-cost-latency.php`. |\n"
            . "| Actual dev spend is not invented. | Implemented | Missing clinical-document cost renders as unknown; available Tier 2 spend is shown separately. |\n"
            . "| p50/p95 latency is reported honestly. | Implemented | Placeholder clinical latency is labeled separately from measured live/deployed latency. |\n"
            . "| Bottleneck analysis exists. | Implemented | Uses stage timings when present and deterministic fallback drivers otherwise. |\n",
            gmdate('Y-m-d'),
            $run->clinicalCaseCount,
            $run->clinicalVerdict,
            $run->clinicalLatencyPlaceholder() ? 'a placeholder' : 'measured',
            $this->formatCost($run->tier2EstimatedCostUsd),
            $run->tier2ProviderModel ?? 'unknown',
            $this->formatInt($run->tier2InputTokens),
            $this->formatInt($run->tier2OutputTokens),
            $tier2P50,
            $tier2P95,
            $smokeP50,
            $smokeP95,
            $this->evidenceTable($run->evidencePaths),
            $run->clinicalExecutedAt,
            $run->clinicalCaseCount,
            $run->clinicalVerdict,
            $clinicalP50,
            $run->clinicalLatencyPlaceholder() ? 'Instrumentation placeholder, not runtime proof.' : 'Measured from clinical handoffs.',
            $clinicalP95,
            $run->clinicalLatencyPlaceholder() ? 'Instrumentation placeholder, not runtime proof.' : 'Measured from clinical handoffs.',
            $tier2P50,
            $tier2P95,
            $smokeP50,
            $smokeP95,
            $this->formatCost($run->tier2EstimatedCostUsd),
            $this->bottleneckSection($run->stageTimingsMs),
        )) . "\n";
    }

    /** @param list<int> $values */
    private function percentile(array $values, int $percentile): ?int
    {
        if ($values === []) {
            return null;
        }
        sort($values);
        $index = (int) ceil(($percentile / 100) * count($values)) - 1;

        return $values[max(0, min($index, count($values) - 1))];
    }

    private function formatLatency(?int $latencyMs): string
    {
        return $latencyMs === null ? 'unknown' : sprintf('%d ms', $latencyMs);
    }

    private function formatCost(?float $cost): string
    {
        return $cost === null ? 'unknown/not recorded' : sprintf('$%.6f', $cost);
    }

    private function formatInt(?int $value): string
    {
        return $value === null ? 'unknown' : (string) $value;
    }

    /** @param list<string> $paths */
    private function evidenceTable(array $paths): string
    {
        $rows = ["| Artifact | Role |", "| --- | --- |"];
        foreach ($paths as $path) {
            $rows[] = sprintf('| `%s` | Source input for this rendered report. |', $this->displayPath($path));
        }

        return implode("\n", $rows);
    }

    private function displayPath(string $path): string
    {
        $agentForgePosition = strpos($path, '/agent-forge/');
        if ($agentForgePosition !== false) {
            return substr($path, $agentForgePosition + 1);
        }

        return $path;
    }

    /** @param array<string, int> $stageTimingsMs */
    private function bottleneckSection(array $stageTimingsMs): string
    {
        if ($stageTimingsMs !== []) {
            $lines = ["Stage-timing drivers from available artifacts:"];
            $rank = 0;
            foreach ($stageTimingsMs as $stage => $latencyMs) {
                $lines[] = sprintf('%d. `%s` - `%d ms` aggregate.', ++$rank, $stage, $latencyMs);
                if ($rank >= 5) {
                    break;
                }
            }

            return implode("\n", $lines);
        }

        return implode("\n", [
            'Fallback drivers when stage timings are unavailable:',
            '1. Extraction for scanned or image-backed clinical documents.',
            '2. Embedding and vector index writes.',
            '3. Retrieval fan-out across sparse search, vector search, and rerank.',
            '4. Draft model call after evidence assembly.',
            '5. Persistence, duplicate checks, identity gates, and retraction handling.',
        ]);
    }
}
