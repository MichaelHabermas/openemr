<?php

/**
 * Minimal query seam for AgentForge repository tests.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\AgentForge;

interface QueryExecutor
{
    /**
     * @param list<mixed> $binds
     * @return list<array<string, mixed>>
     */
    public function fetchRecords(string $sql, array $binds = [], ?Deadline $deadline = null): array;
}
