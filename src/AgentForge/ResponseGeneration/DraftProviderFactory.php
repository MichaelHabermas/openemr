<?php

/**
 * Default AgentForge draft provider selection.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\AgentForge\ResponseGeneration;

use OpenEMR\AgentForge\Llm\HttpClientBuilder;

final class DraftProviderFactory
{
    public static function createDefault(): DraftProvider
    {
        return self::create(DraftProviderConfig::fromEnvironment());
    }

    public static function create(DraftProviderConfig $config): DraftProvider
    {
        return match (DraftProviderMode::from($config->mode)) {
            DraftProviderMode::Fixture => new FixtureDraftProvider(),
            DraftProviderMode::Disabled => new DisabledDraftProvider(),
            DraftProviderMode::OpenAi => new OpenAiDraftProvider(
                HttpClientBuilder::withRetryMiddleware(
                    'https://api.openai.com',
                    $config->timeoutSeconds,
                    $config->connectTimeoutSeconds,
                    'draft_provider_retry',
                ),
                (string) $config->apiKey,
                $config->model,
                $config->inputCostPerMillionTokens,
                $config->outputCostPerMillionTokens,
            ),
            DraftProviderMode::Anthropic => new AnthropicDraftProvider(
                HttpClientBuilder::withRetryMiddleware(
                    'https://api.anthropic.com',
                    $config->timeoutSeconds,
                    $config->connectTimeoutSeconds,
                    'draft_provider_retry',
                ),
                (string) $config->apiKey,
                $config->model,
                $config->inputCostPerMillionTokens,
                $config->outputCostPerMillionTokens,
                $config->cacheWriteCostPerMillionTokens,
                $config->cacheReadCostPerMillionTokens,
            ),
        };
    }
}
