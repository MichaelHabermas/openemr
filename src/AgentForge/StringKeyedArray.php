<?php

/**
 * Keeps only string array keys from mixed-key sources (e.g. DB rows, nested log context).
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\AgentForge;

final class StringKeyedArray
{
    /**
     * @param array<mixed> $source
     * @return array<string, mixed>
     */
    public static function filter(array $source): array
    {
        $result = [];
        foreach ($source as $key => $value) {
            if (is_string($key)) {
                $result[$key] = $value;
            }
        }

        return $result;
    }
}
