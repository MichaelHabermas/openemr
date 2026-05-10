<?php

/**
 * Audit log transport that reads from a Docker Compose service container.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\AgentForge\Observability;

final readonly class DockerComposeAuditLogTransport implements AuditLogTransport
{
    public function __construct(
        private string $composeFilePath,
    ) {
    }

    /** @return list<string> */
    public function grepLines(string $pattern, string $logPath, int $maxLines = 10): array
    {
        $remoteCommand = sprintf(
            'grep -F %s %s | tail -n %d',
            escapeshellarg($pattern),
            escapeshellarg($logPath),
            $maxLines,
        );
        $command = sprintf(
            'docker compose -f %s exec -T openemr sh -lc %s',
            escapeshellarg($this->composeFilePath),
            escapeshellarg($remoteCommand),
        );

        try {
            return AuditLogShellExecutor::exec($command);
        } catch (\RuntimeException) {
            return [];
        }
    }
}
