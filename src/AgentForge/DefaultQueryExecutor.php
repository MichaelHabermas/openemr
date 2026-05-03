<?php

/**
 * QueryUtils-backed AgentForge query executor.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\AgentForge;

use OpenEMR\Common\Database\QueryUtils;

final class DefaultQueryExecutor implements QueryExecutor
{
    public function fetchRecords(string $sql, array $binds = [], ?Deadline $deadline = null): array
    {
        return QueryUtils::fetchRecords(self::applyDeadline($sql, $deadline), $binds);
    }

    /**
     * Inject a `MAX_EXECUTION_TIME(<ms>)` MySQL optimizer hint into the leading SELECT
     * so a single hung query cannot exceed the request's remaining budget.
     */
    private static function applyDeadline(string $sql, ?Deadline $deadline): string
    {
        if ($deadline === null || $deadline->budgetMs < 0) {
            return $sql;
        }

        $remainingMs = $deadline->remainingMs();
        if ($remainingMs <= 0) {
            return $sql;
        }

        $trimmed = ltrim($sql);
        if (stripos($trimmed, 'SELECT ') !== 0) {
            return $sql;
        }
        if (stripos($trimmed, 'MAX_EXECUTION_TIME') !== false) {
            return $sql;
        }

        $prefixLength = strlen($sql) - strlen($trimmed);

        return substr($sql, 0, $prefixLength)
            . 'SELECT /*+ MAX_EXECUTION_TIME(' . $remainingMs . ') */ '
            . substr($trimmed, 7);
    }
}
