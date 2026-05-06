<?php

/**
 * Shared git code version helper for AgentForge script runners.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

function agentforge_scripts_code_version(string $repoRoot): string
{
    $headPath = $repoRoot . '/.git/HEAD';
    if (!is_file($headPath)) {
        return 'unknown';
    }

    $head = trim((string) file_get_contents($headPath));
    if (str_starts_with($head, 'ref: ')) {
        $refPath = $repoRoot . '/.git/' . substr($head, 5);
        if (is_file($refPath)) {
            return substr(trim((string) file_get_contents($refPath)), 0, 12);
        }

        return 'unknown';
    }

    return substr($head, 0, 12);
}
