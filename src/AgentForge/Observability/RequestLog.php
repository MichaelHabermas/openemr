<?php

/**
 * PHI-minimized sensitive AgentForge request log entry.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\AgentForge\Observability;

use DateTimeImmutable;
use DateTimeInterface;
use OpenEMR\AgentForge\Auth\PatientId;

final readonly class RequestLog
{
    public function __construct(
        public string $requestId,
        public ?int $userId,
        public ?int $patientId,
        public string $decision,
        public int $latencyMs,
        public DateTimeImmutable $timestamp,
        public ?AgentTelemetry $telemetry = null,
        public ?string $conversationId = null,
        public ?TraceId $traceId = null,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toContext(): array
    {
        $context = [
            'request_id' => $this->requestId,
            'user_id' => $this->userId,
            'patient_ref' => $this->patientId !== null
                ? PatientRefHasher::createDefault()->hash(new PatientId($this->patientId))
                : null,
            'decision' => $this->decision,
            'latency_ms' => $this->latencyMs,
            'timestamp' => $this->timestamp->format(DateTimeInterface::ATOM),
            'conversation_id' => $this->conversationId,
            'trace_id' => $this->traceId?->value,
        ];

        return SensitiveLogPolicy::sanitizeContext(array_merge(
            $context,
            ($this->telemetry ?? AgentTelemetry::notRun($this->decision))->toContext(),
        ));
    }
}
