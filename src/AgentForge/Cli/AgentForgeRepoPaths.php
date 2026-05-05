<?php

/**
 * Resolve the OpenEMR repository root from AgentForge script locations.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\AgentForge\Cli;

use InvalidArgumentException;

final class AgentForgeRepoPaths
{
    /**
     * @param non-empty-string $agentForgeScriptsDirectory Absolute path to agent-forge/scripts
     */
    public static function fromScriptsDirectory(string $agentForgeScriptsDirectory): string
    {
        $root = dirname($agentForgeScriptsDirectory, 2);

        return self::assertRepoRoot($root, $agentForgeScriptsDirectory);
    }

    /**
     * @param non-empty-string $agentForgeScriptsLibDirectory Absolute path to agent-forge/scripts/lib
     */
    public static function fromScriptsLibDirectory(string $agentForgeScriptsLibDirectory): string
    {
        $root = dirname($agentForgeScriptsLibDirectory, 3);

        return self::assertRepoRoot($root, $agentForgeScriptsLibDirectory);
    }

    private static function assertRepoRoot(string $candidate, string $anchorPath): string
    {
        if ($candidate === '' || !is_dir($candidate . '/vendor') || !is_dir($candidate . '/agent-forge')) {
            throw new InvalidArgumentException(
                sprintf('Could not resolve OpenEMR repo root from path: %s', $anchorPath)
            );
        }

        return $candidate;
    }
}
