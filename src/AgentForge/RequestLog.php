<?php

/**
 * PHI-free AgentForge request log entry.
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
     * @return array{
     *     request_id: string,
     *     user_id: ?int,
     *     patient_id: ?int,
     *     decision: string,
     *     latency_ms: int,
     *     timestamp: string,
     *     question_type: string,
     *     tools_called: list<string>,
     *     source_ids: list<string>,
     *     model: string,
     *     input_tokens: int,
     *     output_tokens: int,
     *     estimated_cost: ?float,
     *     failure_reason: ?string,
     *     verifier_result: string
     * }
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

        return array_merge($context, ($this->telemetry ?? AgentTelemetry::notRun($this->decision))->toContext());
    }
}
