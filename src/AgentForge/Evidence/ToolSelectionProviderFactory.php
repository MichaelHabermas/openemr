<?php

/**
 * Builds the AgentForge chart-section selector from draft-provider configuration.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\AgentForge\Evidence;

use OpenEMR\AgentForge\Llm\HttpClientBuilder;
use OpenEMR\AgentForge\ResponseGeneration\DraftProviderConfig;
use OpenEMR\AgentForge\ResponseGeneration\DraftProviderMode;

final class ToolSelectionProviderFactory
{
    public static function createDefault(): ?ToolSelectionProvider
    {
        return self::create(DraftProviderConfig::fromEnvironment());
    }

    /**
     * Returns null for Fixture/Disabled modes — callers fall back to deterministic planning.
     */
    public static function create(DraftProviderConfig $config): ?ToolSelectionProvider
    {
        return match (DraftProviderMode::from($config->mode)) {
            DraftProviderMode::OpenAi => new OpenAiToolSelectionProvider(
                HttpClientBuilder::withRetryMiddleware(
                    'https://api.openai.com',
                    $config->timeoutSeconds,
                    $config->connectTimeoutSeconds,
                    'tool_selection_retry',
                ),
                (string) $config->apiKey,
                $config->model,
            ),
            DraftProviderMode::Anthropic => new AnthropicToolSelectionProvider(
                HttpClientBuilder::withRetryMiddleware(
                    'https://api.anthropic.com',
                    $config->timeoutSeconds,
                    $config->connectTimeoutSeconds,
                    'tool_selection_retry',
                ),
                (string) $config->apiKey,
                $config->model,
            ),
            DraftProviderMode::Fixture, DraftProviderMode::Disabled => null,
        };
    }
}
