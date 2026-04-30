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
    public function fetchRecords(string $sql, array $binds = []): array
    {
        $records = [];
        foreach (QueryUtils::fetchRecords($sql, $binds) as $record) {
            $records[] = $this->stringKeyedRow($record);
        }

        return $records;
    }

    /**
     * @param array<mixed> $record
     * @return array<string, mixed>
     */
    private function stringKeyedRow(array $record): array
    {
        $row = [];
        foreach ($record as $key => $value) {
            if (is_string($key)) {
                $row[$key] = $value;
            }
        }

        return $row;
    }
}
