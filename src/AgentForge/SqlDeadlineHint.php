<?php

/**
 * Inject a `MAX_EXECUTION_TIME(<ms>)` MySQL optimizer hint into the first SELECT
 * so a single hung query cannot outlast the request's remaining budget.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\AgentForge;

final readonly class SqlDeadlineHint
{
    /**
     * Returns the SQL with a MAX_EXECUTION_TIME hint injected, or the original SQL
     * if no deadline applies. Handles both direct `SELECT ...` and parenthesized
     * `(SELECT ...) UNION ALL (SELECT ...)` forms.
     */
    public static function apply(string $sql, ?Deadline $deadline): string
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

        $replacement = 'SELECT /*+ MAX_EXECUTION_TIME(' . $remainingMs . ') */ ';
        $patched = preg_replace('/^(\s*\(*\s*)SELECT\s+/i', '$1' . $replacement, $sql, 1, $count);
        if ($patched === null || $count === 0) {
            return $sql;
        }

        return $patched;
    }
}
