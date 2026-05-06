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

use OpenEMR\AgentForge\StringKeyedArray;

final class AnswerCitationCoverageRubric implements Rubric
{
    public function name(): string
    {
        return 'answer_citation_coverage';
    }

    public function evaluate(RubricInputs $inputs): RubricResult
    {
        if ($inputs->case->expectedRubrics->expectedFor($this->name()) === null) {
            return new RubricResult($this->name(), RubricStatus::NotApplicable, 'Answer citation coverage is not required.');
        }

        $coverage = $inputs->output->answer['citation_coverage'] ?? null;
        if (!is_array($coverage)) {
            return new RubricResult($this->name(), RubricStatus::Fail, 'Answer citation coverage was not reported.');
        }
        $coverage = StringKeyedArray::filter($coverage);

        if ($inputs->case->expectedAnswer->everyPatientClaimHasCitation && !$this->claimGroupCovered($coverage, 'patient_claims')) {
            return new RubricResult($this->name(), RubricStatus::Fail, 'At least one patient claim lacks a citation.');
        }

        if ($inputs->case->expectedAnswer->everyGuidelineClaimHasCitation && !$this->claimGroupCovered($coverage, 'guideline_claims')) {
            return new RubricResult($this->name(), RubricStatus::Fail, 'At least one guideline claim lacks a citation.');
        }
        if ($inputs->case->expectedAnswer->everyGuidelineClaimHasCitation && !$this->guidelineCitationDetailsCovered($inputs)) {
            return new RubricResult($this->name(), RubricStatus::Fail, 'Guideline citation details were not reported for every guideline claim.');
        }

        $facts = $inputs->output->extraction['facts'] ?? [];
        if (is_array($facts)) {
            $mismatch = $this->firstCitationAccuracyMismatch($facts);
            if ($mismatch !== null) {
                return new RubricResult($this->name(), RubricStatus::Fail, $mismatch);
            }
        }

        return new RubricResult($this->name(), RubricStatus::Pass, 'Answer-level citation coverage is complete and citations match extracted values.');
    }

    /** @param array<string, mixed> $coverage */
    private function claimGroupCovered(array $coverage, string $group): bool
    {
        $counts = $coverage[$group] ?? null;
        if (!is_array($counts)) {
            return false;
        }

        $total = $counts['total'] ?? null;
        $cited = $counts['cited'] ?? null;

        return is_int($total) && is_int($cited) && $total > 0 && $total === $cited;
    }

    private function guidelineCitationDetailsCovered(RubricInputs $inputs): bool
    {
        $coverage = $inputs->output->answer['citation_coverage'] ?? [];
        if (!is_array($coverage)) {
            return false;
        }
        $coverage = StringKeyedArray::filter($coverage);
        $counts = $coverage['guideline_claims'] ?? null;
        if (!is_array($counts) || !is_int($counts['total'] ?? null)) {
            return false;
        }

        $total = $counts['total'];
        if ($total < 1) {
            return false;
        }

        $citations = $inputs->output->answer['guideline_citations'] ?? null;
        if (!is_array($citations) || count($citations) < $total) {
            return false;
        }

        foreach ($citations as $citation) {
            if (!is_array($citation)) {
                return false;
            }
            $quote = $citation['quote_or_value'] ?? null;
            if (!is_string($quote) || trim($quote) === '') {
                return false;
            }
        }

        return true;
    }

    /**
     * @param array<int|string, mixed> $facts
     */
    private function firstCitationAccuracyMismatch(array $facts): ?string
    {
        foreach ($facts as $fact) {
            if (!is_array($fact)) {
                continue;
            }
            $citation = $fact['citation'] ?? null;
            if (!is_array($citation)) {
                continue;
            }
            $value = $fact['value'] ?? null;
            $quote = $citation['quote_or_value'] ?? null;

            if (!is_string($value) || $value === '') {
                continue;
            }
            if (!is_string($quote) || trim($quote) === '') {
                $fieldPath = is_string($fact['field_path'] ?? null) ? $fact['field_path'] : '(unnamed)';
                return sprintf('Citation for %s has empty quote_or_value.', $fieldPath);
            }

            if (stripos($quote, $value) === false && stripos($value, $quote) === false) {
                $fieldPath = is_string($fact['field_path'] ?? null) ? $fact['field_path'] : '(unnamed)';
                return sprintf(
                    'Citation quote_or_value (%s) does not contain extracted value (%s) for %s.',
                    $quote,
                    $value,
                    $fieldPath,
                );
            }
        }

        return null;
    }
}
