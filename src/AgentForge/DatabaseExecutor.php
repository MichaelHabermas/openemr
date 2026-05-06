<?php

/**
 * Single AgentForge SQL boundary used by every Sql*Repository.
 *
 * Reads (`fetchRecords`) accept an optional `Deadline` so the executor can
 * inject a `MAX_EXECUTION_TIME` MySQL optimizer hint when a budget applies.
 * Writes (`executeStatement`, `executeAffected`, `insert`) intentionally do
 * not — write deadlines are enforced at the orchestration layer, not in SQL.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\AgentForge;

interface DatabaseExecutor
{
    /**
     * @param list<mixed> $binds
     * @return list<array<string, mixed>>
     */
    public function fetchRecords(string $sql, array $binds = [], ?Deadline $deadline = null): array;

    /** @param list<mixed> $binds */
    public function executeStatement(string $sql, array $binds = []): void;

    /** @param list<mixed> $binds */
    public function executeAffected(string $sql, array $binds = []): int;

    /** @param list<mixed> $binds */
    public function insert(string $sql, array $binds = []): int;
}
