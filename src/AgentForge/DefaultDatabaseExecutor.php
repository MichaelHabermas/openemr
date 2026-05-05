<?php

/**
 * QueryUtils-backed AgentForge SQL executor.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\AgentForge;

use OpenEMR\Common\Database\QueryUtils;

final class DefaultDatabaseExecutor implements DatabaseExecutor
{
    public function fetchRecords(string $sql, array $binds = []): array
    {
        return array_map(
            self::stringKeyed(...),
            QueryUtils::fetchRecords($sql, $binds),
        );
    }

    public function executeStatement(string $sql, array $binds = []): void
    {
        QueryUtils::sqlStatementThrowException($sql, $binds);
    }

    public function insert(string $sql, array $binds = []): int
    {
        return QueryUtils::sqlInsert($sql, $binds);
    }

    /**
     * @param array<mixed> $record
     * @return array<string, mixed>
     */
    private static function stringKeyed(array $record): array
    {
        $out = [];
        foreach ($record as $key => $value) {
            if (is_string($key)) {
                $out[$key] = $value;
            }
        }

        return $out;
    }
}
