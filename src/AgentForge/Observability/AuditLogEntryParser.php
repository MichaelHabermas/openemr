<?php

/**
 * Extract structured fields from a PSR-3 audit log line.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\AgentForge\Observability;

use OpenEMR\AgentForge\StringKeyedArray;

final class AuditLogEntryParser
{
    /** @return array<string, mixed> */
    public static function extractFields(string $logLine): array
    {
        $start = strpos($logLine, '{');
        if ($start === false) {
            return [];
        }

        $candidate = substr($logLine, $start);
        $decoded = json_decode($candidate, true);
        if (is_array($decoded)) {
            return StringKeyedArray::filter($decoded);
        }

        $end = strrpos($logLine, '}');
        if ($end === false || $end <= $start) {
            return [];
        }

        $decoded = json_decode(substr($logLine, $start, ($end - $start) + 1), true);
        if (is_array($decoded)) {
            return StringKeyedArray::filter($decoded);
        }

        return [];
    }
}
