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

use DateTimeImmutable;
use OpenEMR\AgentForge\Observability\RequestLog;
use OpenEMR\AgentForge\Observability\RequestLogger;

final readonly class AgentRequestLifecycle
{
    public function __construct(
        private AgentRequestHandler $handler,
        private RequestLogger $logger,
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
        ?int $startTime = null,
    ): AgentRequestResult {
        $startedAt = new DateTimeImmutable();
        $start = $startTime ?? hrtime(true);
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
            latencyMs: max(0, (int) floor((hrtime(true) - $start) / 1_000_000)),
            timestamp: $startedAt,
            telemetry: $result->telemetry,
            conversationId: $result->conversationId,
        ));

        return $result;
    }
}
