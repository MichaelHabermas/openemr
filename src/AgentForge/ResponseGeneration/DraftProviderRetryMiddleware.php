<?php

/**
 * Guzzle middleware that retries transient draft-provider failures within a deadline.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\AgentForge\ResponseGeneration;

use Closure;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Promise\Create as PromiseCreate;
use GuzzleHttp\Promise\PromiseInterface;
use LogicException;
use OpenEMR\AgentForge\Deadline;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Throwable;

final readonly class DraftProviderRetryMiddleware
{
    public const DEADLINE_OPTION = 'agentforge_deadline';
    public const RETRY_COUNT_OPTION = 'agentforge_retry_count';
    public const MAX_RETRIES = 2;
    public const BASE_DELAY_MS = 50;
    public const MAX_DELAY_MS = 200;
    public const MIN_CALL_BUDGET_MS = 250;

    /** @var list<int> */
    public const RETRYABLE_STATUS_CODES = [408, 425, 429, 500, 502, 503, 504];

    /** @var Closure(int): int */
    private Closure $sleeperUs;

    /** @var Closure(int): int */
    private Closure $jitterMs;

    /** @var Closure(RequestInterface, array<string, mixed>): PromiseInterface */
    private Closure $nextHandler;

    /**
     * @param Closure(int): int|null $sleeperUs Microsecond sleeper (defaults to usleep). Override for tests.
     * @param Closure(int): int|null $jitterMs Jitter generator returning ms in [0, max]. Defaults to random_int.
     */
    public function __construct(
        callable $nextHandler,
        ?Closure $sleeperUs = null,
        ?Closure $jitterMs = null,
    ) {
        $this->nextHandler = $nextHandler instanceof Closure
            ? $nextHandler
            : Closure::fromCallable($nextHandler);
        $this->sleeperUs = $sleeperUs ?? static function (int $us): int {
            usleep($us);
            return $us;
        };
        $this->jitterMs = $jitterMs ?? static fn (int $maxMs): int => random_int(0, max(0, $maxMs));
    }

    /**
     * Handler-stack factory. Push via `$stack->push(DraftProviderRetryMiddleware::create())`.
     *
     * @return Closure(callable): self
     */
    public static function create(?Closure $sleeperUs = null, ?Closure $jitterMs = null): Closure
    {
        return static fn (callable $handler): self => new self($handler, $sleeperUs, $jitterMs);
    }

    /** @param array<string, mixed> $options */
    public function __invoke(RequestInterface $request, array $options): PromiseInterface
    {
        $options[self::RETRY_COUNT_OPTION] ??= 0;

        return $this->dispatch($request, $options);
    }

    /** @param array<string, mixed> $options */
    private function dispatch(RequestInterface $request, array $options): PromiseInterface
    {
        return $this->invokeNextHandler($request, $options)->then(
            function (ResponseInterface $response) use ($request, $options) {
                $retry = $this->planRetry($options, $response, null);
                if ($retry !== null) {
                    return $this->scheduleRetry($request, $options, $retry);
                }

                return $response;
            },
            function ($reason) use ($request, $options) {
                $exception = $reason instanceof Throwable ? $reason : null;
                $retry = $exception === null ? null : $this->planRetry($options, null, $exception);
                if ($retry !== null) {
                    return $this->scheduleRetry($request, $options, $retry);
                }

                return PromiseCreate::rejectionFor($reason);
            },
        );
    }

    /** @param array<string, mixed> $options */
    private function invokeNextHandler(RequestInterface $request, array $options): PromiseInterface
    {
        $promise = ($this->nextHandler)($request, $options);
        if (!$promise instanceof PromiseInterface) {
            throw new LogicException('Guzzle handler returned a non-promise value.');
        }

        return $promise;
    }

    /**
     * @param array<string, mixed> $options
     * @return array{attempt: int, delayMs: int}|null
     */
    private function planRetry(array $options, ?ResponseInterface $response, ?Throwable $reason): ?array
    {
        if ($this->retryCount($options) >= self::MAX_RETRIES) {
            return null;
        }

        if (!$this->responseIsRetryable($response, $reason)) {
            return null;
        }

        $nextAttempt = $this->retryCount($options) + 1;
        $delayMs = $this->computeDelayMs($nextAttempt);
        $deadline = $options[self::DEADLINE_OPTION] ?? null;
        if ($deadline instanceof Deadline && $deadline->budgetMs >= 0) {
            $remaining = $deadline->remainingMs();
            if ($remaining <= 0 || $remaining < $delayMs + self::MIN_CALL_BUDGET_MS) {
                return null;
            }
        }

        return ['attempt' => $nextAttempt, 'delayMs' => $delayMs];
    }

    private function responseIsRetryable(?ResponseInterface $response, ?Throwable $reason): bool
    {
        if ($reason instanceof ConnectException) {
            return true;
        }

        if ($response === null) {
            return false;
        }

        return in_array($response->getStatusCode(), self::RETRYABLE_STATUS_CODES, true);
    }

    /**
     * @param array<string, mixed> $options
     * @param array{attempt: int, delayMs: int} $plan
     */
    private function scheduleRetry(RequestInterface $request, array $options, array $plan): PromiseInterface
    {
        ($this->sleeperUs)($plan['delayMs'] * 1000);

        $options[self::RETRY_COUNT_OPTION] = $plan['attempt'];

        return $this->dispatch($request, $options);
    }

    private function computeDelayMs(int $attempt): int
    {
        $base = min(self::BASE_DELAY_MS << ($attempt - 1), self::MAX_DELAY_MS);
        $jitter = ($this->jitterMs)(max(1, intdiv($base, 2)));

        return $base + $jitter;
    }

    /** @param array<string, mixed> $options */
    private function retryCount(array $options): int
    {
        $value = $options[self::RETRY_COUNT_OPTION] ?? 0;

        return is_int($value) ? $value : 0;
    }
}
