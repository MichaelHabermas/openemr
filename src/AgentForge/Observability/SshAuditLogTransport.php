<?php

/**
 * Audit log transport that reads from a remote host via SSH.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\AgentForge\Observability;

final readonly class SshAuditLogTransport implements AuditLogTransport
{
    public function __construct(
        private string $sshHost,
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
            'ssh -o BatchMode=yes -o ConnectTimeout=10 %s %s',
            escapeshellarg($this->sshHost),
            escapeshellarg($remoteCommand),
        );

        try {
            return AuditLogShellExecutor::exec($command);
        } catch (\RuntimeException) {
            return [];
        }
    }
}
