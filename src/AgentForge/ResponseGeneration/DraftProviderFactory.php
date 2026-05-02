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

use GuzzleHttp\Client;
use GuzzleHttp\HandlerStack;

final class DraftProviderFactory
{
    public static function createDefault(): DraftProvider
    {
        return self::create(DraftProviderConfig::fromEnvironment());
    }

    public static function create(DraftProviderConfig $config): DraftProvider
    {
        return match ($config->mode) {
            DraftProviderConfig::MODE_FIXTURE => new FixtureDraftProvider(),
            DraftProviderConfig::MODE_DISABLED => new DisabledDraftProvider(),
            DraftProviderConfig::MODE_OPENAI => new OpenAiDraftProvider(
                self::buildClient('https://api.openai.com', $config),
                (string) $config->apiKey,
                $config->model,
                $config->inputCostPerMillionTokens,
                $config->outputCostPerMillionTokens,
            ),
            DraftProviderConfig::MODE_ANTHROPIC => new AnthropicDraftProvider(
                self::buildClient('https://api.anthropic.com', $config),
                (string) $config->apiKey,
                $config->model,
                $config->inputCostPerMillionTokens,
                $config->outputCostPerMillionTokens,
                $config->cacheWriteCostPerMillionTokens,
                $config->cacheReadCostPerMillionTokens,
            ),
            default => throw new \RuntimeException(sprintf(
                'AgentForge draft provider mode "%s" is not configured.',
                $config->mode,
            )),
        };
    }

    private static function buildClient(string $baseUri, DraftProviderConfig $config): Client
    {
        $stack = HandlerStack::create();
        $stack->push(DraftProviderRetryMiddleware::create(), 'draft_provider_retry');

        return new Client([
            'base_uri' => $baseUri,
            'timeout' => $config->timeoutSeconds,
            'connect_timeout' => $config->connectTimeoutSeconds,
            'handler' => $stack,
        ]);
    }
}
