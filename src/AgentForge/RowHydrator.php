<?php

/**
 * Static helpers for hydrating typed values from raw SQL row arrays.
 *
 * Centralizes the handful of `intValue` / `stringValue` / `nullableInt` /
 * `nullableString` shapes that were previously duplicated as private methods
 * across every Sql*Repository.
 *
 * Deliberately omits a `dateTimeValue()` helper: timezone-aware parsing
 * belongs in the AgentForge\Time clock layer, not in a row hydrator.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\AgentForge;

use InvalidArgumentException;

final class RowHydrator
{
    public static function intValue(mixed $value, string $field): int
    {
        if (!is_scalar($value)) {
            throw new InvalidArgumentException("Expected scalar {$field}.");
        }

        return (int) $value;
    }

    public static function stringValue(mixed $value, string $field): string
    {
        if (!is_scalar($value)) {
            throw new InvalidArgumentException("Expected scalar {$field}.");
        }

        return (string) $value;
    }

    public static function nullableInt(mixed $value, string $field): ?int
    {
        if ($value === null) {
            return null;
        }

        return self::intValue($value, $field);
    }

    public static function nullableString(mixed $value, string $field): ?string
    {
        if ($value === null) {
            return null;
        }

        return self::stringValue($value, $field);
    }
}
