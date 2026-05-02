<?php

/**
 * Isolated tests for AgentForge draft-provider retry middleware.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Tests\Isolated\AgentForge;

use Closure;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\BadResponseException;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use OpenEMR\AgentForge\AgentForgeClock;
use OpenEMR\AgentForge\Deadline;
use OpenEMR\AgentForge\ResponseGeneration\DraftProviderRetryMiddleware;
use PHPUnit\Framework\TestCase;

final class DraftProviderRetryMiddlewareTest extends TestCase
{
    public function testRetriesTwiceOnTransientStatusUntilSuccess(): void
    {
        $mock = new MockHandler([
            new Response(429, [], '{"error":"rate limited"}'),
            new Response(429, [], '{"error":"rate limited"}'),
            new Response(200, [], '{"ok":true}'),
        ]);
        $sleeps = [];
        $client = $this->buildClient($mock, $sleeps);

        $response = $client->request('POST', '/v1/messages', ['http_errors' => false]);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertCount(0, $mock);
        $this->assertCount(2, $sleeps);
    }

    public function testGivesUpAfterMaxRetriesAndReturnsLastResponse(): void
    {
        $mock = new MockHandler([
            new Response(503),
            new Response(503),
            new Response(503),
        ]);
        $sleeps = [];
        $client = $this->buildClient($mock, $sleeps);

        $response = $client->request('POST', '/v1/messages', ['http_errors' => false]);

        $this->assertSame(503, $response->getStatusCode());
        $this->assertCount(0, $mock);
        $this->assertCount(2, $sleeps);
    }

    public function testRetriesOnConnectException(): void
    {
        $mock = new MockHandler([
            new ConnectException('connection refused', new Request('POST', '/v1/messages')),
            new Response(200, [], '{"ok":true}'),
        ]);
        $sleeps = [];
        $client = $this->buildClient($mock, $sleeps);

        $response = $client->request('POST', '/v1/messages');

        $this->assertSame(200, $response->getStatusCode());
        $this->assertCount(1, $sleeps);
    }

    public function testNonRetryableStatusCodeIsNotRetried(): void
    {
        $mock = new MockHandler([
            new Response(400, [], '{"error":"bad request"}'),
        ]);
        $sleeps = [];
        $client = $this->buildClient($mock, $sleeps);

        $this->expectException(BadResponseException::class);

        try {
            $client->request('POST', '/v1/messages');
        } finally {
            $this->assertSame([], $sleeps);
        }
    }

    public function testAbortsRetryWhenDeadlineExhausted(): void
    {
        $clock = new RetryAdvancingClock([0, 50, 5000]);
        $deadline = new Deadline($clock, 100);
        $mock = new MockHandler([
            new Response(429),
            new Response(429),
            new Response(200, [], '{"ok":true}'),
        ]);
        $sleeps = [];
        $client = $this->buildClient($mock, $sleeps);

        $response = $client->request('POST', '/v1/messages', [
            DraftProviderRetryMiddleware::DEADLINE_OPTION => $deadline,
            'http_errors' => false,
        ]);

        $this->assertSame(429, $response->getStatusCode());
        $this->assertCount(1, $sleeps);
    }

    public function testBackoffIsExponentialWithJitter(): void
    {
        $mock = new MockHandler([
            new Response(503),
            new Response(503),
            new Response(503),
        ]);
        $sleeps = [];
        $jitterCalls = [];
        $client = $this->buildClient(
            $mock,
            $sleeps,
            jitter: static function (int $maxMs) use (&$jitterCalls): int {
                $jitterCalls[] = $maxMs;
                return 0;
            },
        );

        $client->request('POST', '/v1/messages', ['http_errors' => false]);

        $this->assertSame([50_000, 100_000], $sleeps);
        $this->assertSame([25, 50], $jitterCalls);
    }

    /**
     * @param array<int, mixed> $sleeps
     */
    private function buildClient(MockHandler $mock, array &$sleeps, ?Closure $jitter = null): Client
    {
        $stack = HandlerStack::create($mock);
        $stack->push(DraftProviderRetryMiddleware::create(
            sleeperUs: function (int $us) use (&$sleeps): int {
                $sleeps[] = $us;
                return $us;
            },
            jitterMs: $jitter ?? static fn (int $maxMs): int => 0,
        ));

        return new Client(['handler' => $stack]);
    }
}

final class RetryAdvancingClock implements AgentForgeClock
{
    /** @param list<int> $ticks */
    public function __construct(private array $ticks)
    {
    }

    public function nowMs(): int
    {
        if ($this->ticks === []) {
            return 0;
        }

        return array_shift($this->ticks);
    }
}
