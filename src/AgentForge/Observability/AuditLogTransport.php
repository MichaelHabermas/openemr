<?php

/**
 * Transport interface for reading audit log entries from different sources.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\AgentForge\Observability;

interface AuditLogTransport
{
    /** @return list<string> Raw log lines matching the pattern, most recent last */
    public function grepLines(string $pattern, string $logPath, int $maxLines = 10): array;
}
