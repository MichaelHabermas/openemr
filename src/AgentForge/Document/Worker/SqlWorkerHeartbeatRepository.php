<?php

/**
 * SQL-backed AgentForge document worker heartbeat repository.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\AgentForge\Document\Worker;

use DateTimeImmutable;
use OpenEMR\AgentForge\DatabaseExecutor;
use OpenEMR\AgentForge\RowHydrator;

final readonly class SqlWorkerHeartbeatRepository implements WorkerHeartbeatRepository
{
    public function __construct(private DatabaseExecutor $executor)
    {
    }

    public function upsert(WorkerHeartbeat $heartbeat): void
    {
        $this->executor->executeStatement(
            'INSERT INTO clinical_document_worker_heartbeats '
            . '(worker, process_id, status, iteration_count, jobs_processed, jobs_failed, '
            . 'started_at, last_heartbeat_at, stopped_at) '
            . 'VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?) '
            . 'ON DUPLICATE KEY UPDATE '
            . 'process_id = VALUES(process_id), '
            . 'status = VALUES(status), '
            . 'iteration_count = VALUES(iteration_count), '
            . 'jobs_processed = VALUES(jobs_processed), '
            . 'jobs_failed = VALUES(jobs_failed), '
            . 'started_at = VALUES(started_at), '
            . 'last_heartbeat_at = VALUES(last_heartbeat_at), '
            . 'stopped_at = VALUES(stopped_at)',
            [
                $heartbeat->workerName->value,
                $heartbeat->processId,
                $heartbeat->status->value,
                $heartbeat->iterationCount,
                $heartbeat->jobsProcessed,
                $heartbeat->jobsFailed,
                $heartbeat->startedAt->format('Y-m-d H:i:s'),
                $heartbeat->lastHeartbeatAt->format('Y-m-d H:i:s'),
                $heartbeat->stoppedAt?->format('Y-m-d H:i:s'),
            ],
        );
    }

    public function findByWorker(WorkerName $workerName): ?WorkerHeartbeat
    {
        $records = $this->executor->fetchRecords(
            'SELECT worker, process_id, status, iteration_count, jobs_processed, jobs_failed, '
            . 'started_at, last_heartbeat_at, stopped_at '
            . 'FROM clinical_document_worker_heartbeats WHERE worker = ? LIMIT 1',
            [$workerName->value],
        );

        return $records === [] ? null : $this->hydrate($records[0]);
    }

    /** @param array<string, mixed> $record */
    private function hydrate(array $record): WorkerHeartbeat
    {
        return new WorkerHeartbeat(
            workerName: WorkerName::fromStringOrThrow(RowHydrator::stringValue($record['worker'] ?? null, 'worker')),
            processId: RowHydrator::intValue($record['process_id'] ?? null, 'process_id'),
            status: WorkerStatus::fromStringOrThrow(RowHydrator::stringValue($record['status'] ?? null, 'status')),
            iterationCount: RowHydrator::intValue($record['iteration_count'] ?? null, 'iteration_count'),
            jobsProcessed: RowHydrator::intValue($record['jobs_processed'] ?? null, 'jobs_processed'),
            jobsFailed: RowHydrator::intValue($record['jobs_failed'] ?? null, 'jobs_failed'),
            startedAt: new DateTimeImmutable(RowHydrator::stringValue($record['started_at'] ?? null, 'started_at')),
            lastHeartbeatAt: new DateTimeImmutable(RowHydrator::stringValue($record['last_heartbeat_at'] ?? null, 'last_heartbeat_at')),
            stoppedAt: self::optionalDateTime($record['stopped_at'] ?? null),
        );
    }

    private static function optionalDateTime(mixed $value): ?DateTimeImmutable
    {
        if ($value === null || $value === '') {
            return null;
        }

        return new DateTimeImmutable(RowHydrator::stringValue($value, 'date_time'));
    }
}
