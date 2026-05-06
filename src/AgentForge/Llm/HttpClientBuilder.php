<?php

/**
 * Builds Guzzle HTTP clients for AgentForge LLM providers.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\AgentForge\Llm;

use GuzzleHttp\Client;
use GuzzleHttp\HandlerStack;
use OpenEMR\AgentForge\ResponseGeneration\DraftProviderRetryMiddleware;

final class HttpClientBuilder
{
    public static function withRetryMiddleware(
        string $baseUri,
        float $timeoutSeconds,
        float $connectTimeoutSeconds,
        string $middlewareLabel,
    ): Client {
        $stack = HandlerStack::create();
        $stack->push(DraftProviderRetryMiddleware::create(), $middlewareLabel);

        return new Client([
            'base_uri' => $baseUri,
            'timeout' => $timeoutSeconds,
            'connect_timeout' => $connectTimeoutSeconds,
            'handler' => $stack,
        ]);
    }
}
