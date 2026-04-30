<?php

/**
 * Deterministic missing-data hints for fixture-mode chart questions.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\AgentForge;

final class KnownMissingDataPolicy
{
    /** @return list<string> */
    public static function missingSectionsFor(AgentQuestion $question, EvidenceBundle $bundle): array
    {
        $normalizedQuestion = strtolower($question->value);
        if (!str_contains($normalizedQuestion, 'microalbumin')) {
            return [];
        }

        foreach ($bundle->items as $item) {
            $haystack = strtolower($item->displayLabel . ' ' . $item->value);
            if (str_contains($haystack, 'microalbumin')) {
                return [];
            }
        }

        return ['Urine microalbumin result not found in the chart.'];
    }
}
