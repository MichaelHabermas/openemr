<?php

/**
 * Recording Guzzle ClientInterface fake for AgentForge LLM provider tests.
 *
 * Captures the JSON payload and headers of the last `request()` call and
 * replays a fixed JSON response body. Other ClientInterface methods throw
 * `BadMethodCallException` because no AgentForge provider uses them today.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Tests\Isolated\AgentForge\Support;

use BadMethodCallException;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Psr7\Response;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

final class RecordingHttpClient implements ClientInterface
{
    /** @var array<string, mixed>|null */
    private ?array $payload = null;

    /** @var array<string, string> */
    private array $headers = [];

    /** @param array<string, mixed> $responseBody */
    public function __construct(private readonly array $responseBody)
    {
    }

    /** @param array<mixed> $options */
    public function send(RequestInterface $request, array $options = []): ResponseInterface
    {
        throw new BadMethodCallException('send is not used by this test client.');
    }

    /** @param array<mixed> $options */
    public function sendAsync(RequestInterface $request, array $options = []): PromiseInterface
    {
        throw new BadMethodCallException('sendAsync is not used by this test client.');
    }

    /** @param array<mixed> $options */
    public function request(string $method, $uri, array $options = []): ResponseInterface
    {
        $json = $options['json'] ?? null;
        if (!is_array($json)) {
            throw new BadMethodCallException('Expected JSON request payload.');
        }
        $this->payload = $this->stringKeyedArray($json);

        $headers = $options['headers'] ?? [];
        if (is_array($headers)) {
            $stringHeaders = [];
            foreach ($headers as $name => $value) {
                if (!is_string($name) || !is_string($value)) {
                    throw new BadMethodCallException('Expected string-keyed header values.');
                }
                $stringHeaders[$name] = $value;
            }
            $this->headers = $stringHeaders;
        }

        return new Response(200, [], json_encode($this->responseBody, JSON_THROW_ON_ERROR));
    }

    /** @param array<mixed> $options */
    public function requestAsync(string $method, $uri, array $options = []): PromiseInterface
    {
        throw new BadMethodCallException('requestAsync is not used by this test client.');
    }

    public function getConfig(?string $option = null): mixed
    {
        return null;
    }

    /** @return array<string, mixed> */
    public function lastPayload(): array
    {
        if ($this->payload === null) {
            throw new BadMethodCallException('No request payload was recorded.');
        }

        return $this->payload;
    }

    /** @return array<string, string> */
    public function lastHeaders(): array
    {
        return $this->headers;
    }

    /**
     * @param array<mixed> $source
     * @return array<string, mixed>
     */
    private function stringKeyedArray(array $source): array
    {
        $result = [];
        foreach ($source as $key => $value) {
            if (!is_string($key)) {
                throw new BadMethodCallException('Expected string-keyed JSON payload.');
            }
            $result[$key] = $value;
        }

        return $result;
    }
}
