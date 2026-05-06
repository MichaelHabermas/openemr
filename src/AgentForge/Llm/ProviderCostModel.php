<?php

/**
 * Per-million-token cost pair for a single LLM model.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\AgentForge\Llm;

final readonly class ProviderCostModel
{
    public function __construct(
        public ?float $inputCostPerMillionTokens,
        public ?float $outputCostPerMillionTokens,
    ) {
    }

    public static function unknown(): self
    {
        return new self(null, null);
    }
}
