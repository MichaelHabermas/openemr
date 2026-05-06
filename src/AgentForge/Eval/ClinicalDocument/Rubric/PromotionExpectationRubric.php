<?php

/**
 * Clinical document eval support for AgentForge.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\AgentForge\Eval\ClinicalDocument\Rubric;

final class PromotionExpectationRubric implements Rubric
{
    public function name(): string
    {
        return 'promotion_expectations';
    }

    public function evaluate(RubricInputs $inputs): RubricResult
    {
        if ($inputs->case->expectedPromotions === []) {
            return new RubricResult($this->name(), RubricStatus::NotApplicable, 'No promotion expectations declared.');
        }

        foreach ($inputs->case->expectedPromotions as $expected) {
            if (!$this->hasMatchingRecord($inputs->output->promotions, $expected)) {
                return new RubricResult($this->name(), RubricStatus::Fail, 'Expected promotion record was not emitted.');
            }
        }

        return new RubricResult($this->name(), RubricStatus::Pass, 'Expected promotion records were emitted.');
    }

    /**
     * @param list<array<string, mixed>> $actualRecords
     * @param array<string, mixed> $expected
     */
    private function hasMatchingRecord(array $actualRecords, array $expected): bool
    {
        foreach ($actualRecords as $actual) {
            if (($expected['table'] ?? null) !== null && ($actual['table'] ?? null) !== $expected['table']) {
                continue;
            }
            $valueContains = $expected['value_contains'] ?? null;
            if (is_string($valueContains)) {
                $value = $actual['value'] ?? null;
                if (!is_scalar($value) || stripos((string) $value, $valueContains) === false) {
                    continue;
                }
            }
            if (!$this->hasCitation($actual) || ($actual['fact_fingerprint'] ?? '') === '') {
                continue;
            }

            return true;
        }

        return false;
    }

    /** @param array<string, mixed> $actual */
    private function hasCitation(array $actual): bool
    {
        $citation = $actual['citation'] ?? null;
        if (!is_array($citation)) {
            return false;
        }

        $quote = $citation['quote_or_value'] ?? null;

        return is_string($quote) && trim($quote) !== '';
    }
}
