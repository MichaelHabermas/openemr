<?php

/**
 * Shared dispatch template for AgentForge HTTP-backed LLM providers.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\AgentForge\Llm;

use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;
use OpenEMR\AgentForge\Deadline;
use OpenEMR\AgentForge\ResponseGeneration\DraftProviderRetryMiddleware;
use Psr\Http\Message\ResponseInterface;
use Throwable;

abstract readonly class AbstractLlmProvider
{
    public function __construct(protected ClientInterface $client)
    {
    }

    abstract protected function configuredTimeoutSeconds(): float;

    /** @return class-string<Throwable> */
    abstract protected function exceptionClass(): string;

    /**
     * @param array<string, mixed> $options
     */
    final protected function dispatch(
        string $method,
        string $path,
        array $options,
        Deadline $deadline,
        string $operationLabel,
    ): ResponseInterface {
        if ($deadline->exceeded()) {
            throw $this->buildException(
                sprintf('Deadline exceeded before %s request.', $operationLabel),
            );
        }

        $options['timeout'] = min($this->configuredTimeoutSeconds(), $deadline->remainingSeconds());
        $options[DraftProviderRetryMiddleware::DEADLINE_OPTION] = $deadline;

        try {
            return $this->client->request($method, $path, $options);
        } catch (GuzzleException $exception) {
            throw $this->buildException(
                sprintf('%s request failed.', $operationLabel),
                $exception,
            );
        }
    }

    private function buildException(string $message, ?Throwable $previous = null): Throwable
    {
        $class = $this->exceptionClass();

        return new $class($message, 0, $previous);
    }
}
