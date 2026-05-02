<?php

/**
 * Deterministic missing-data hints for chart questions.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\AgentForge\Verification;

use OpenEMR\AgentForge\Evidence\EvidenceBundle;
use OpenEMR\AgentForge\Handlers\AgentQuestion;

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

        return ['Urine microalbumin not found in the chart.'];
    }
}
