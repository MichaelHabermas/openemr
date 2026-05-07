<?php

/**
 * Normalized inputs for the clinical document cost/latency report.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\AgentForge\Reporting;

final readonly class ClinicalDocumentCostLatencyRun
{
    /**
     * @param array<string, mixed> $clinicalSummary
     * @param list<int> $clinicalHandoffLatenciesMs
     * @param list<int> $tier2LatenciesMs
     * @param list<int> $deployedSmokeLatenciesMs
     * @param array<string, int> $stageTimingsMs
     * @param list<string> $evidencePaths
     */
    public function __construct(
        public string $clinicalExecutedAt,
        public string $clinicalVerdict,
        public int $clinicalCaseCount,
        public array $clinicalSummary,
        public array $clinicalHandoffLatenciesMs,
        public ?float $tier2EstimatedCostUsd,
        public ?int $tier2InputTokens,
        public ?int $tier2OutputTokens,
        public ?string $tier2ProviderModel,
        public array $tier2LatenciesMs,
        public array $deployedSmokeLatenciesMs,
        public array $stageTimingsMs,
        public array $evidencePaths,
    ) {
    }

    public function clinicalLatencyPlaceholder(): bool
    {
        return $this->clinicalHandoffLatenciesMs !== []
            && count(array_filter($this->clinicalHandoffLatenciesMs, static fn (int $latency): bool => $latency !== 0)) === 0;
    }
}
