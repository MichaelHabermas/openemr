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

use GuzzleHttp\Client;
use GuzzleHttp\HandlerStack;
use OpenEMR\AgentForge\ResponseGeneration\DraftProviderConfig;
use OpenEMR\AgentForge\ResponseGeneration\DraftProviderRetryMiddleware;

final class ToolSelectionProviderFactory
{
    public static function createDefault(): ?ToolSelectionProvider
    {
        return self::create(DraftProviderConfig::fromEnvironment());
    }

    public static function create(DraftProviderConfig $config): ?ToolSelectionProvider
    {
        return match ($config->mode) {
            DraftProviderConfig::MODE_OPENAI => new OpenAiToolSelectionProvider(
                self::buildClient('https://api.openai.com', $config),
                (string) $config->apiKey,
                $config->model,
            ),
            DraftProviderConfig::MODE_ANTHROPIC => new AnthropicToolSelectionProvider(
                self::buildClient('https://api.anthropic.com', $config),
                (string) $config->apiKey,
                $config->model,
            ),
            default => null,
        };
    }

    private static function buildClient(string $baseUri, DraftProviderConfig $config): Client
    {
        $stack = HandlerStack::create();
        $stack->push(DraftProviderRetryMiddleware::create(), 'tool_selection_retry');

        return new Client([
            'base_uri' => $baseUri,
            'timeout' => $config->timeoutSeconds,
            'connect_timeout' => $config->connectTimeoutSeconds,
            'handler' => $stack,
        ]);
    }
}
