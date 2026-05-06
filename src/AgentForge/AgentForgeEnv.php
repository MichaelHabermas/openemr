<?php

/**
 * Shared environment-variable helpers for AgentForge configuration.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\AgentForge;

final class AgentForgeEnv
{
    public static function string(string $name): ?string
    {
        $value = getenv($name, true);
        if (!is_string($value) || trim($value) === '') {
            return null;
        }

        return $value;
    }

    public static function float(string $name): ?float
    {
        $value = self::string($name);
        if ($value === null || !is_numeric($value)) {
            return null;
        }

        return (float) $value;
    }

    public static function int(string $name): ?int
    {
        $value = self::string($name);
        if ($value === null || !ctype_digit($value)) {
            return null;
        }

        return (int) $value;
    }
}
