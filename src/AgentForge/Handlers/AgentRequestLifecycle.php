<?php

/**
 * Executes and records one AgentForge request lifecycle.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\AgentForge\Handlers;

use OpenEMR\AgentForge\Observability\RequestLog;
use OpenEMR\AgentForge\Observability\RequestLogger;
use OpenEMR\AgentForge\Observability\TraceId;
use OpenEMR\AgentForge\Time\MonotonicClock;
use Psr\Clock\ClockInterface;

final readonly class AgentRequestLifecycle
{
    public function __construct(
        private AgentRequestHandler $handler,
        private RequestLogger $logger,
        private MonotonicClock $clock,
        private ClockInterface $wallClock,
    ) {
    }

    /** @param array<string, mixed> $post */
    public function handle(
        string $method,
        array $post,
        ?int $sessionUserId,
        ?int $sessionPatientId,
        bool $hasMedicalRecordAcl,
        bool $csrfValid,
        string $requestId,
        ?int $startTimeMs = null,
    ): AgentRequestResult {
        $startedAt = $this->wallClock->now();
        $startMs = $startTimeMs ?? $this->clock->nowMs();
        $result = $this->handler->handle(
            $method,
            $post,
            $sessionUserId,
            $sessionPatientId,
            $hasMedicalRecordAcl,
            $csrfValid,
            $requestId,
        );

        $this->logger->record(new RequestLog(
            requestId: $requestId,
            userId: $sessionUserId,
            patientId: $result->logPatientId,
            decision: $result->decision,
            latencyMs: max(0, $this->clock->nowMs() - $startMs),
            timestamp: $startedAt,
            telemetry: $result->telemetry,
            conversationId: $result->conversationId,
            traceId: $result->telemetry?->traceId !== null
                ? TraceId::fromString($result->telemetry->traceId)
                : null,
        ));

        return $result;
    }
}
