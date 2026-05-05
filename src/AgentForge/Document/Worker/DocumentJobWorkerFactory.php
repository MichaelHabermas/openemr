<?php

/**
 * Factory for the standalone AgentForge document worker CLI.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\AgentForge\Document\Worker;

use DateTimeImmutable;
use OpenEMR\AgentForge\DefaultDatabaseExecutor;
use OpenEMR\AgentForge\Document\SqlDocumentJobRepository;
use OpenEMR\AgentForge\Observability\PatientRefHasher;
use OpenEMR\BC\ServiceContainer;

final class DocumentJobWorkerFactory
{
    public static function createDefault(WorkerName $workerName): DocumentJobWorker
    {
        $executor = new DefaultDatabaseExecutor();
        $jobs = new SqlDocumentJobRepository($executor);

        return new DocumentJobWorker(
            $workerName,
            new SqlJobClaimer($jobs, $executor),
            $jobs,
            new OpenEmrDocumentLoader(),
            new NoopDocumentJobProcessor(),
            new SqlWorkerHeartbeatRepository($executor),
            ServiceContainer::getLogger(),
            PatientRefHasher::createDefault(),
            getmypid() ?: 1,
        );
    }

    public static function markStopped(WorkerName $workerName, int $processId): void
    {
        $heartbeats = new SqlWorkerHeartbeatRepository(new DefaultDatabaseExecutor());
        $current = $heartbeats->findByWorker($workerName);
        $now = new DateTimeImmutable();
        $iterationCount = 0;
        $jobsProcessed = 0;
        $jobsFailed = 0;
        $startedAt = $now;
        if ($current instanceof WorkerHeartbeat) {
            $iterationCount = $current->iterationCount;
            $jobsProcessed = $current->jobsProcessed;
            $jobsFailed = $current->jobsFailed;
            $startedAt = $current->startedAt;
        }

        $heartbeats->upsert(new WorkerHeartbeat(
            workerName: $workerName,
            processId: $processId,
            status: WorkerStatus::Stopped,
            iterationCount: $iterationCount,
            jobsProcessed: $jobsProcessed,
            jobsFailed: $jobsFailed,
            startedAt: $startedAt,
            lastHeartbeatAt: $now,
            stoppedAt: $now,
        ));
    }
}
