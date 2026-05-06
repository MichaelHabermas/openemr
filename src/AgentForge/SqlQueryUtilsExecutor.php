<?php

/**
 * The single concrete `DatabaseExecutor` implementation, backed by
 * `OpenEMR\Common\Database\QueryUtils`.
 *
 * Read paths apply `SqlDeadlineHint` when a `Deadline` is supplied so a
 * single hung query cannot outlast the request's remaining budget.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\AgentForge;

use OpenEMR\Common\Database\QueryUtils;

final class SqlQueryUtilsExecutor implements DatabaseExecutor
{
    public function fetchRecords(string $sql, array $binds = [], ?Deadline $deadline = null): array
    {
        return array_map(
            StringKeyedArray::filter(...),
            QueryUtils::fetchRecords(SqlDeadlineHint::apply($sql, $deadline), $binds),
        );
    }

    public function executeStatement(string $sql, array $binds = []): void
    {
        QueryUtils::sqlStatementThrowException($sql, $binds);
    }

    public function executeAffected(string $sql, array $binds = []): int
    {
        QueryUtils::sqlStatementThrowException($sql, $binds);
        $affected = QueryUtils::affectedRows();

        return $affected === false ? 0 : $affected;
    }

    public function insert(string $sql, array $binds = []): int
    {
        return QueryUtils::sqlInsert($sql, $binds);
    }
}
