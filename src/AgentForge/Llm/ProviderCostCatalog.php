<?php

/**
 * Default per-million-token costs for known AgentForge LLM models.
 *
 * Single source of truth for model pricing used by both DraftProviderConfig
 * and ExtractionProviderConfig when no explicit costs are supplied. Lookups
 * for unknown models return ProviderCostModel::unknown() (both nulls);
 * explicit operator-supplied costs always override defaults.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\AgentForge\Llm;

final class ProviderCostCatalog
{
    public static function lookup(string $model): ProviderCostModel
    {
        return match ($model) {
            'gpt-4o-mini' => new ProviderCostModel(0.15, 0.60),
            'gpt-4o' => new ProviderCostModel(2.50, 10.00),
            'claude-haiku-4-5-20251001' => new ProviderCostModel(1.00, 5.00),
            default => ProviderCostModel::unknown(),
        };
    }
}
