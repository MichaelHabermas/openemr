<?php

/**
 * Shared shell execution for audit log transport implementations.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\AgentForge\Observability;

use RuntimeException;
use Symfony\Component\Process\Process;

final class AuditLogShellExecutor
{
    /**
     * @return list<string> Non-empty trimmed output lines
     */
    public static function exec(string $command): array
    {
        $process = Process::fromShellCommandline($command);
        $process->setTimeout(30);
        $process->run();

        $stdout = $process->getOutput();
        if (!$process->isSuccessful() && trim($stdout) === '') {
            throw new RuntimeException(
                sprintf(
                    'Audit log grep exit %d: %s',
                    $process->getExitCode() ?? -1,
                    trim($process->getErrorOutput()) !== '' ? trim($process->getErrorOutput()) : 'no output',
                ),
            );
        }

        $lines = [];
        foreach (explode("\n", $stdout) as $line) {
            $trimmed = trim($line);
            if ($trimmed !== '') {
                $lines[] = $trimmed;
            }
        }

        return $lines;
    }
}
