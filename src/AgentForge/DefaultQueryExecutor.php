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
     * so a single hung query cannot exceed the request's remaining budget. Handles both
     * direct `SELECT ...` and parenthesized `(SELECT ...) UNION ALL (...)` forms.
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

        if (stripos($sql, 'MAX_EXECUTION_TIME') !== false) {
            return $sql;
        }

        $hint = '/*+ MAX_EXECUTION_TIME(' . $remainingMs . ') */ ';
        $replacement = 'SELECT ' . $hint;

        $patched = preg_replace('/^(\s*\(*\s*)SELECT\s+/i', '$1' . $replacement, $sql, 1, $count);
        if ($patched === null || $count === 0) {
            return $sql;
        }

        return $patched;
    }
}
