<?php

/**
 * Parses hook-layer scalar inputs into strictly positive integers (OpenEMR entity ids).
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\AgentForge\Document;

final class StrictPositiveInt
{
    /**
     * Invalid, zero, or negative values yield null (hook ignores input without logging).
     */
    public static function tryParse(mixed $value): ?int
    {
        $parsed = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

        return $parsed === false ? null : (int) $parsed;
    }
}
