<?php

/**
 * PHI-minimized sensitive AgentForge request log entry.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\AgentForge;

use DateTimeImmutable;
use DateTimeInterface;

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
            'patient_id' => $this->patientId,
            'decision' => $this->decision,
            'latency_ms' => $this->latencyMs,
            'timestamp' => $this->timestamp->format(DateTimeInterface::ATOM),
        ];

        return SensitiveLogPolicy::sanitizeContext(array_merge(
            $context,
            ($this->telemetry ?? AgentTelemetry::notRun($this->decision))->toContext(),
        ));
    }
}
