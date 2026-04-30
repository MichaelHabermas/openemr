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
        return QueryUtils::fetchRecords($sql, $binds);
    }
}
