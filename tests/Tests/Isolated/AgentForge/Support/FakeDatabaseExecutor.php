<?php

/**
 * Programmable in-memory DatabaseExecutor fake for isolated tests.
 *
 * Records every call. Reads return queued result sets in FIFO order;
 * writes return queued affected/insert ids. Per-test fakes remain valid
 * — this is for tests that just need deterministic playback without
 * coupling to a specific repository's row shapes.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Tests\Isolated\AgentForge\Support;

use OpenEMR\AgentForge\DatabaseExecutor;
use OpenEMR\AgentForge\Deadline;
use RuntimeException;

final class FakeDatabaseExecutor implements DatabaseExecutor
{
    /** @var list<array{sql: string, binds: list<mixed>, deadline: ?Deadline}> */
    public array $reads = [];

    /** @var list<array{sql: string, binds: list<mixed>}> */
    public array $statements = [];

    /** @var list<array{sql: string, binds: list<mixed>}> */
    public array $affectedCalls = [];

    /** @var list<array{sql: string, binds: list<mixed>}> */
    public array $inserts = [];

    /** @var list<list<array<string, mixed>>> */
    private array $resultSets = [];

    /** @var list<int> */
    private array $affectedRowsQueue = [];

    /** @var list<int> */
    private array $insertIdsQueue = [];

    /** @param list<array<string, mixed>> $rows */
    public function queueResult(array $rows): void
    {
        $this->resultSets[] = $rows;
    }

    public function queueAffectedRows(int $count): void
    {
        $this->affectedRowsQueue[] = $count;
    }

    public function queueInsertId(int $id): void
    {
        $this->insertIdsQueue[] = $id;
    }

    public function fetchRecords(string $sql, array $binds = [], ?Deadline $deadline = null): array
    {
        $this->reads[] = ['sql' => $sql, 'binds' => $binds, 'deadline' => $deadline];

        return array_shift($this->resultSets) ?? [];
    }

    public function executeStatement(string $sql, array $binds = []): void
    {
        $this->statements[] = ['sql' => $sql, 'binds' => $binds];
    }

    public function executeAffected(string $sql, array $binds = []): int
    {
        $this->affectedCalls[] = ['sql' => $sql, 'binds' => $binds];

        if ($this->affectedRowsQueue === []) {
            return 0;
        }

        return array_shift($this->affectedRowsQueue);
    }

    public function insert(string $sql, array $binds = []): int
    {
        $this->inserts[] = ['sql' => $sql, 'binds' => $binds];

        if ($this->insertIdsQueue === []) {
            throw new RuntimeException('FakeDatabaseExecutor::insert called with no queued insert id.');
        }

        return array_shift($this->insertIdsQueue);
    }
}
