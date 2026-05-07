<?php

/**
 * PHI-minimized model call usage telemetry.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\AgentForge\Observability;

final readonly class ModelUsageTelemetry
{
    public function __construct(
        public string $provider,
        public string $model,
        public int $inputTokens,
        public int $outputTokens,
        public ?float $estimatedCost,
        public ?int $cacheCreationInputTokens = null,
        public ?int $cacheReadInputTokens = null,
    ) {
    }

    /**
     * @return array{
     *     provider: string,
     *     model: string,
     *     input_tokens: int,
     *     output_tokens: int,
     *     estimated_cost: ?float,
     *     cache_creation_input_tokens: ?int,
     *     cache_read_input_tokens: ?int
     * }
     */
    public function toContext(): array
    {
        return [
            'provider' => $this->provider,
            'model' => $this->model,
            'input_tokens' => $this->inputTokens,
            'output_tokens' => $this->outputTokens,
            'estimated_cost' => $this->estimatedCost,
            'cache_creation_input_tokens' => $this->cacheCreationInputTokens,
            'cache_read_input_tokens' => $this->cacheReadInputTokens,
        ];
    }
}
