<?php

/**
 * Shared token-cost arithmetic for AgentForge model providers.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\AgentForge\Llm;

final class TokenCostEstimator
{
    public static function estimate(
        int $inputTokens,
        int $outputTokens,
        ?float $inputCostPerMillionTokens,
        ?float $outputCostPerMillionTokens,
    ): ?float {
        if ($inputCostPerMillionTokens === null || $outputCostPerMillionTokens === null) {
            return null;
        }

        return (($inputTokens / 1_000_000) * $inputCostPerMillionTokens)
            + (($outputTokens / 1_000_000) * $outputCostPerMillionTokens);
    }

    public static function estimateWithCache(
        int $inputTokens,
        int $outputTokens,
        int $cacheCreationTokens,
        int $cacheReadTokens,
        ?float $inputCostPerMillionTokens,
        ?float $outputCostPerMillionTokens,
        ?float $cacheWriteCostPerMillionTokens,
        ?float $cacheReadCostPerMillionTokens,
    ): ?float {
        if ($inputCostPerMillionTokens === null || $outputCostPerMillionTokens === null) {
            return null;
        }

        $cacheWriteRate = $cacheWriteCostPerMillionTokens ?? ($inputCostPerMillionTokens * 1.25);
        $cacheReadRate = $cacheReadCostPerMillionTokens ?? ($inputCostPerMillionTokens * 0.10);

        return (($inputTokens / 1_000_000) * $inputCostPerMillionTokens)
            + (($cacheCreationTokens / 1_000_000) * $cacheWriteRate)
            + (($cacheReadTokens / 1_000_000) * $cacheReadRate)
            + (($outputTokens / 1_000_000) * $outputCostPerMillionTokens);
    }
}
