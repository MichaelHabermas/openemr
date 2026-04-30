<?php

/**
 * Typed accessors for mixed chart-evidence database rows.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\AgentForge;

final class EvidenceRowValue
{
    /** @param array<string, mixed> $row */
    public static function string(array $row, string $key): string
    {
        $value = $row[$key] ?? null;
        if (is_scalar($value) || $value === null) {
            return trim((string) $value);
        }

        return '';
    }

    /** @param array<string, mixed> $row */
    public static function firstString(array $row, string ...$keys): string
    {
        foreach ($keys as $key) {
            $value = self::string($row, $key);
            if ($value !== '') {
                return $value;
            }
        }

        return '';
    }

    /** @param array<string, mixed> $row */
    public static function dateOnly(array $row, string ...$keys): string
    {
        $date = self::firstString($row, ...$keys);

        return $date === '' ? 'unknown' : substr($date, 0, 10);
    }

    /** @param array<string, mixed> $row */
    public static function truthy(array $row, string $key): bool
    {
        return in_array($row[$key] ?? null, [1, '1', true], true);
    }
}
