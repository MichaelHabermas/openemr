<?php

/**
 * Minimal AgentForge SQL executor for repositories that need reads and writes.
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
    public function fetchRecords(string $sql, array $binds = []): array;

    /** @param list<mixed> $binds */
    public function executeStatement(string $sql, array $binds = []): void;

    /** @param list<mixed> $binds */
    public function executeAffected(string $sql, array $binds = []): int;

    /** @param list<mixed> $binds */
    public function insert(string $sql, array $binds = []): int;
}
