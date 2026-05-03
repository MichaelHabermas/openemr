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
        if (str_contains($normalizedQuestion, 'birth weight') || str_contains($normalizedQuestion, 'birthweight')) {
            return self::missingIfUnsupported($bundle, ['birth weight', 'birthweight'], 'Birth weight not found in the chart.');
        }

        if (str_contains($normalizedQuestion, 'microalbumin')) {
            return self::missingIfUnsupported($bundle, ['microalbumin'], 'Urine microalbumin not found in the chart.');
        }

        return [];
    }

    /**
     * @param list<string> $needles
     * @return list<string>
     */
    private static function missingIfUnsupported(EvidenceBundle $bundle, array $needles, string $message): array
    {
        foreach ($bundle->items as $item) {
            $haystack = strtolower($item->displayLabel . ' ' . $item->value);
            foreach ($needles as $needle) {
                if (str_contains($haystack, $needle)) {
                    return [];
                }
            }
        }

        return [$message];
    }
}
