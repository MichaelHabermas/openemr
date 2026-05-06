<?php

/**
 * Isolated tests for AgentForge document worker heartbeat SQL repository.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Tests\Isolated\AgentForge\Document\Worker;

use DateTimeImmutable;
use OpenEMR\AgentForge\DatabaseExecutor;
use OpenEMR\AgentForge\Deadline;
use OpenEMR\AgentForge\Document\Worker\SqlWorkerHeartbeatRepository;
use OpenEMR\AgentForge\Document\Worker\WorkerHeartbeat;
use OpenEMR\AgentForge\Document\Worker\WorkerName;
use OpenEMR\AgentForge\Document\Worker\WorkerStatus;
use PHPUnit\Framework\TestCase;

final class SqlWorkerHeartbeatRepositoryTest extends TestCase
{
    public function testUpsertUsesWorkerUniqueKeyAndCounterBinds(): void
    {
        $executor = new HeartbeatSqlExecutor();
        $heartbeat = new WorkerHeartbeat(
            workerName: WorkerName::IntakeExtractor,
            processId: 12345,
            status: WorkerStatus::Running,
            iterationCount: 7,
            jobsProcessed: 3,
            jobsFailed: 1,
            startedAt: new DateTimeImmutable('2026-05-05 00:00:00'),
            lastHeartbeatAt: new DateTimeImmutable('2026-05-05 00:01:00'),
            stoppedAt: null,
        );

        (new SqlWorkerHeartbeatRepository($executor))->upsert($heartbeat);

        $this->assertStringContainsString('INSERT INTO clinical_document_worker_heartbeats', $executor->statements[0]['sql']);
        $this->assertStringContainsString(
            '(worker, process_id, status, iteration_count, jobs_processed, jobs_failed, started_at, last_heartbeat_at, stopped_at)',
            $executor->statements[0]['sql'],
        );
        $this->assertStringContainsString('VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)', $executor->statements[0]['sql']);
        $this->assertStringContainsString('ON DUPLICATE KEY UPDATE', $executor->statements[0]['sql']);
        $this->assertStringContainsString('started_at = VALUES(started_at)', $executor->statements[0]['sql']);
        $this->assertStringContainsString('last_heartbeat_at = VALUES(last_heartbeat_at)', $executor->statements[0]['sql']);
        $this->assertStringContainsString('stopped_at = VALUES(stopped_at)', $executor->statements[0]['sql']);
        $this->assertSame(
            ['intake-extractor', 12345, 'running', 7, 3, 1, '2026-05-05 00:00:00', '2026-05-05 00:01:00', null],
            $executor->statements[0]['binds'],
        );
    }

    public function testFindByWorkerSelectsAndHydratesHeartbeat(): void
    {
        $executor = new HeartbeatSqlExecutor([
            [
                'worker' => 'intake-extractor',
                'process_id' => 12345,
                'status' => 'stopped',
                'iteration_count' => 7,
                'jobs_processed' => 3,
                'jobs_failed' => 1,
                'started_at' => '2026-05-05 00:00:00',
                'last_heartbeat_at' => '2026-05-05 00:01:00',
                'stopped_at' => '2026-05-05 00:02:00',
            ],
        ]);

        $heartbeat = (new SqlWorkerHeartbeatRepository($executor))->findByWorker(WorkerName::IntakeExtractor);

        $this->assertInstanceOf(WorkerHeartbeat::class, $heartbeat);
        $this->assertSame(WorkerName::IntakeExtractor, $heartbeat->workerName);
        $this->assertSame(WorkerStatus::Stopped, $heartbeat->status);
        $this->assertSame(7, $heartbeat->iterationCount);
        $this->assertSame('2026-05-05 00:02:00', $heartbeat->stoppedAt?->format('Y-m-d H:i:s'));
        $this->assertStringContainsString('FROM clinical_document_worker_heartbeats WHERE worker = ? LIMIT 1', $executor->queries[0]['sql']);
        $this->assertSame(['intake-extractor'], $executor->queries[0]['binds']);
    }
}

final class HeartbeatSqlExecutor implements DatabaseExecutor
{
    /** @var list<array{sql: string, binds: list<mixed>}> */
    public array $queries = [];

    /** @var list<array{sql: string, binds: list<mixed>}> */
    public array $statements = [];

    /** @param list<array<string, mixed>> $records */
    public function __construct(private readonly array $records = [], private readonly int $affectedRows = 1)
    {
    }

    public function fetchRecords(string $sql, array $binds = [], ?Deadline $deadline = null): array
    {
        $this->queries[] = ['sql' => $sql, 'binds' => $binds];

        return $this->records;
    }

    public function executeStatement(string $sql, array $binds = []): void
    {
        $this->statements[] = ['sql' => $sql, 'binds' => $binds];
    }

    public function executeAffected(string $sql, array $binds = []): int
    {
        $this->statements[] = ['sql' => $sql, 'binds' => $binds];

        return $this->affectedRows;
    }

    public function insert(string $sql, array $binds = []): int
    {
        $this->statements[] = ['sql' => $sql, 'binds' => $binds];

        return 1;
    }
}
