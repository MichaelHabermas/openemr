<?php

/**
 * SQL-backed supervisor handoff audit repository.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\AgentForge\Orchestration;

use OpenEMR\AgentForge\DatabaseExecutor;
use OpenEMR\AgentForge\Document\DocumentJob;
use RuntimeException;

final readonly class SqlSupervisorHandoffRepository
{
    private const MAX_REQUEST_ID_LENGTH = 64;
    private const MAX_NODE_LENGTH = 64;
    private const MAX_DECISION_LENGTH = 255;
    private const MAX_TASK_TYPE_LENGTH = 64;
    private const MAX_OUTCOME_LENGTH = 64;
    private const MAX_ERROR_LENGTH = 255;

    public function __construct(private DatabaseExecutor $executor)
    {
    }

    public function recordRequestHandoff(
        string $requestId,
        NodeName $destinationNode,
        string $decisionReason,
        string $taskType,
        string $outcome,
        ?int $latencyMs = null,
        ?string $errorReason = null,
    ): int {
        return $this->insert(
            requestId: $requestId,
            jobId: null,
            destinationNode: $destinationNode->value,
            decisionReason: $decisionReason,
            taskType: $taskType,
            outcome: $outcome,
            latencyMs: $latencyMs,
            errorReason: $errorReason,
        );
    }

    public function record(
        DocumentJob $job,
        SupervisorDecision $decision,
        ?string $requestId = null,
        ?int $latencyMs = null,
        ?string $errorReason = null,
    ): int {
        if ($job->id === null) {
            throw new RuntimeException('Supervisor handoffs require a persisted document job id.');
        }
        if ($decision->targetNode === null) {
            throw new RuntimeException('Supervisor handoffs require a destination node.');
        }

        return $this->insert(
            requestId: $requestId,
            jobId: $job->id->value,
            destinationNode: $decision->targetNode->value,
            decisionReason: $decision->reason,
            taskType: $job->docType->value,
            outcome: $decision->decision,
            latencyMs: $latencyMs,
            errorReason: $errorReason,
        );
    }

    private function insert(
        ?string $requestId,
        ?int $jobId,
        ?string $destinationNode,
        string $decisionReason,
        string $taskType,
        string $outcome,
        ?int $latencyMs,
        ?string $errorReason,
    ): int {
        return $this->executor->insert(
            'INSERT INTO clinical_supervisor_handoffs '
            . '(request_id, job_id, source_node, destination_node, decision_reason, task_type, outcome, '
            . 'latency_ms, error_reason, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())',
            [
                $this->boundedNullable($requestId, self::MAX_REQUEST_ID_LENGTH, 'request id'),
                $jobId,
                NodeName::Supervisor->value,
                $this->boundedRequired($destinationNode, self::MAX_NODE_LENGTH, 'destination node'),
                $this->boundedRequired($decisionReason, self::MAX_DECISION_LENGTH, 'decision reason'),
                $this->boundedRequired($taskType, self::MAX_TASK_TYPE_LENGTH, 'task type'),
                $this->boundedRequired($outcome, self::MAX_OUTCOME_LENGTH, 'outcome'),
                $latencyMs,
                $this->boundedNullable($errorReason, self::MAX_ERROR_LENGTH, 'error reason'),
            ],
        );
    }

    private function boundedRequired(?string $value, int $maxLength, string $label): string
    {
        $bounded = $this->boundedNullable($value, $maxLength, $label);
        if ($bounded === null) {
            throw new RuntimeException(sprintf('Supervisor handoff %s is required.', $label));
        }

        return $bounded;
    }

    private function boundedNullable(?string $value, int $maxLength, string $label): ?string
    {
        if ($value === null) {
            return null;
        }
        $trimmed = trim($value);
        if ($trimmed === '') {
            return null;
        }
        if (strlen($trimmed) > $maxLength) {
            throw new RuntimeException(sprintf('Supervisor handoff %s exceeds the database limit.', $label));
        }

        return $trimmed;
    }
}
