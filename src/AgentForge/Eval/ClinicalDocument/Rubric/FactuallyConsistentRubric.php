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

use OpenEMR\AgentForge\Eval\ClinicalDocument\ExtractionFactExpectation;
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
            return new RubricResult($this->name(), RubricStatus::Fail, 'Extracted facts are not available.', 0.0);
        }

        $expectedFacts = $inputs->case->expectedExtraction->facts;
        $expectedCount = count($expectedFacts);

        if ($expectedCount === 0) {
            return new RubricResult($this->name(), RubricStatus::Pass, 'No facts were expected.', 1.0);
        }

        $matched = 0;
        $missing = [];
        foreach ($expectedFacts as $expectedFact) {
            if ($this->hasMatchingFact($facts, $expectedFact)) {
                $matched++;
                continue;
            }
            $missing[] = is_string($expectedFact['field_path'] ?? null)
                ? $expectedFact['field_path']
                : '(unnamed)';
        }

        $score = $matched / $expectedCount;

        if ($matched === $expectedCount) {
            return new RubricResult(
                $this->name(),
                RubricStatus::Pass,
                sprintf('All %d expected facts present.', $expectedCount),
                $score,
            );
        }

        return new RubricResult(
            $this->name(),
            RubricStatus::Fail,
            sprintf(
                'Matched %d of %d expected facts (recall=%.2f). Missing: %s',
                $matched,
                $expectedCount,
                $score,
                implode(', ', $missing),
            ),
            $score,
        );
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

            if (ExtractionFactExpectation::actualMatchesExpected(StringKeyedArray::filter($actualFact), $expectedFact)) {
                return true;
            }
        }

        return false;
    }
}
