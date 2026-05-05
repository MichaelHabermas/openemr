<?php

declare(strict_types=1);

namespace OpenEMR\AgentForge\Eval\ClinicalDocument\Rubric;

use OpenEMR\AgentForge\StringKeyedArray;

final class FactuallyConsistentRubric implements Rubric
{
    public function name(): string
    {
        return 'factually_consistent';
    }

    public function evaluate(RubricInputs $inputs): RubricResult
    {
        if ($inputs->case->expectedRubrics->expectedFor($this->name()) === null) {
            return new RubricResult($this->name(), RubricStatus::NotApplicable, 'Factual consistency is not required for this case.');
        }

        $facts = $inputs->output->extraction['facts'] ?? [];
        if (!is_array($facts)) {
            return new RubricResult($this->name(), RubricStatus::Fail, 'Extracted facts are not available.');
        }

        foreach ($inputs->case->expectedExtraction->facts as $expectedFact) {
            if (!$this->hasMatchingFact($facts, $expectedFact)) {
                return new RubricResult($this->name(), RubricStatus::Fail, 'Expected fact was not found in extracted output.');
            }
        }

        return new RubricResult($this->name(), RubricStatus::Pass, 'Expected facts are present in extracted output.');
    }

    /**
     * @param array<int|string, mixed> $actualFacts
     * @param array<string, mixed> $expectedFact
     */
    private function hasMatchingFact(array $actualFacts, array $expectedFact): bool
    {
        foreach ($actualFacts as $actualFact) {
            if (!is_array($actualFact)) {
                continue;
            }

            $actualFact = StringKeyedArray::filter($actualFact);
            $matches = true;
            foreach ($expectedFact as $key => $expectedValue) {
                if (str_starts_with((string) $key, 'requires_') || $key === 'confidence_min' || $key === 'field_path') {
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

            if ($matches) {
                return true;
            }
        }

        return false;
    }
}
