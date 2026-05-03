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
        return array_map(
            self::stringKeyed(...),
            QueryUtils::fetchRecords(SqlDeadlineHint::apply($sql, $deadline), $binds),
        );
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
