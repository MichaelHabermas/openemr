<?php

/**
 * Shared matching rules for clinical-document eval expected facts vs extracted facts.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/open-emr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\AgentForge\Eval\ClinicalDocument;

use OpenEMR\AgentForge\StringKeyedArray;

final class ExtractionFactExpectation
{
    /**
     * @param array<string, mixed> $actualFact
     * @param array<string, mixed> $expectedFact
     */
    public static function actualMatchesExpected(array $actualFact, array $expectedFact): bool
    {
        $actualFact = StringKeyedArray::filter($actualFact);
        $matches = true;
        foreach ($expectedFact as $key => $expectedValue) {
            if (str_starts_with((string) $key, 'requires_')) {
                continue;
            }

            if ($key === 'confidence_min') {
                $actualConfidence = $actualFact['confidence'] ?? null;
                $matches = $matches
                    && (is_int($actualConfidence) || is_float($actualConfidence))
                    && (is_int($expectedValue) || is_float($expectedValue))
                    && (float) $actualConfidence >= (float) $expectedValue;
                continue;
            }

            if ($key === 'value_contains') {
                $actualValue = $actualFact['value'] ?? null;
                $matches = $matches && is_scalar($actualValue) && is_scalar($expectedValue) && str_contains((string) $actualValue, (string) $expectedValue);
                continue;
            }

            $actualValue = $actualFact[$key] ?? null;
            $matches = $matches && is_scalar($actualValue) && is_scalar($expectedValue) && (string) $actualValue === (string) $expectedValue;
        }

        return $matches;
    }
}
