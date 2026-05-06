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

final class DocumentFactExpectationRubric implements Rubric
{
    public function name(): string
    {
        return 'document_fact_expectations';
    }

    public function evaluate(RubricInputs $inputs): RubricResult
    {
        if ($inputs->case->expectedDocumentFacts === []) {
            return new RubricResult($this->name(), RubricStatus::NotApplicable, 'No document-fact expectations declared.');
        }

        foreach ($inputs->case->expectedDocumentFacts as $expected) {
            if (!$this->hasMatchingRecord($inputs->output->documentFacts, $expected)) {
                return new RubricResult($this->name(), RubricStatus::Fail, 'Expected document fact record was not emitted.');
            }
        }

        return new RubricResult($this->name(), RubricStatus::Pass, 'Expected document fact records were emitted.');
    }

    /**
     * @param list<array<string, mixed>> $actualRecords
     * @param array<string, mixed> $expected
     */
    private function hasMatchingRecord(array $actualRecords, array $expected): bool
    {
        foreach ($actualRecords as $actual) {
            if (($expected['field_path'] ?? null) !== null && ($actual['field_path'] ?? null) !== $expected['field_path']) {
                continue;
            }
            $classification = $expected['classification'] ?? null;
            if (is_string($classification) && ($actual['fact_type'] ?? null) !== $classification) {
                continue;
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
