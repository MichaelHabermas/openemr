<?php

/**
 * Audit log transport that reads from a local file via grep.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\AgentForge\Observability;

final readonly class LocalFileAuditLogTransport implements AuditLogTransport
{
    /** @return list<string> */
    public function grepLines(string $pattern, string $logPath, int $maxLines = 10): array
    {
        $command = sprintf(
            'grep -F %s %s | tail -n %d',
            escapeshellarg($pattern),
            escapeshellarg($logPath),
            $maxLines,
        );

        try {
            return AuditLogShellExecutor::exec($command);
        } catch (\RuntimeException) {
            return [];
        }
    }
}
