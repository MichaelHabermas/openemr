<?php

/**
 * Clinical document eval structural coverage guardrails.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\AgentForge\Eval\ClinicalDocument\Runner;

use OpenEMR\AgentForge\Eval\ClinicalDocument\Case\EvalCase;
use OpenEMR\AgentForge\Eval\ClinicalDocument\Case\EvalCaseCategory;
use OpenEMR\AgentForge\Eval\ClinicalDocument\Rubric\RubricRegistry;

final class StructuralCoveragePolicy
{
    private const MIN_CASES = 50;
    private const MAX_CASES = 60;

    /** @var array<string, int> */
    private const CATEGORY_MINIMUMS = [
        'lab_pdf_extraction' => 6,
        'intake_form_extraction' => 8,
        'guideline_retrieval' => 8,
        'refusal' => 10,
        'duplicate_upload' => 2,
        'log_audit' => 2,
    ];

    /** @var list<string> */
    private const REQUIRED_TAGS = [
        'clean_typed_lab',
        'scanned_lab',
        'image_only_lab',
        'typed_intake',
        'scanned_intake',
        'unexpected_location',
        'uncertain_allergy',
        'incomplete_collection_date',
        'irrelevant_preference',
        'duplicate_upload',
        'wrong_document_retraction',
        'missing_data',
        'out_of_corpus_guideline',
        'no_phi_logging_trap',
        'follow_up_grounding',
        'citation_regression',
        'combined_document_guideline',
    ];

    /**
     * @param list<EvalCase> $cases
     * @return list<string>
     */
    public function validate(array $cases, RubricRegistry $rubrics): array
    {
        $violations = [];
        $caseCount = count($cases);
        if ($caseCount < self::MIN_CASES || $caseCount > self::MAX_CASES) {
            $violations[] = sprintf('Clinical document golden set must contain %d-%d cases; found %d.', self::MIN_CASES, self::MAX_CASES, $caseCount);
        }

        $categoryCounts = $this->categoryCounts($cases);
        foreach (self::CATEGORY_MINIMUMS as $category => $minimum) {
            if (($categoryCounts[$category] ?? 0) < $minimum) {
                $violations[] = sprintf('Clinical document golden set must include at least %d %s cases.', $minimum, $category);
            }
        }

        foreach (self::REQUIRED_TAGS as $tag) {
            if (!$this->hasCoverageTag($cases, $tag)) {
                $violations[] = sprintf('Clinical document golden set is missing required H1 coverage tag "%s".', $tag);
            }
        }

        foreach ($rubrics->all() as $rubric) {
            if (!$this->hasRubricExpectation($cases, $rubric->name())) {
                $violations[] = sprintf('Clinical document golden set never declares rubric "%s".', $rubric->name());
            }
        }

        foreach ($cases as $case) {
            foreach ($case->coverageTags as $tag) {
                $violation = $this->semanticTagViolation($case, $tag);
                if ($violation !== null) {
                    $violations[] = $violation;
                }
            }
        }

        return $violations;
    }

    /**
     * @param list<EvalCase> $cases
     * @return array<string, int>
     */
    private function categoryCounts(array $cases): array
    {
        $counts = array_fill_keys(array_map(static fn (EvalCaseCategory $category): string => $category->value, EvalCaseCategory::cases()), 0);
        foreach ($cases as $case) {
            $counts[$case->category->value]++;
        }

        return $counts;
    }

    /** @param list<EvalCase> $cases */
    private function hasCoverageTag(array $cases, string $tag): bool
    {
        foreach ($cases as $case) {
            if (in_array($tag, $case->coverageTags, true)) {
                return true;
            }
        }

        return false;
    }

    /** @param list<EvalCase> $cases */
    private function hasRubricExpectation(array $cases, string $rubricName): bool
    {
        if ($rubricName === 'promotion_expectations') {
            return $this->hasExpectedPromotions($cases);
        }
        if ($rubricName === 'document_fact_expectations') {
            return $this->hasExpectedDocumentFacts($cases);
        }

        foreach ($cases as $case) {
            if ($case->expectedRubrics->expectedFor($rubricName) !== null) {
                return true;
            }
        }

        return false;
    }

    private function semanticTagViolation(EvalCase $case, string $tag): ?string
    {
        if ($tag === 'combined_document_guideline' && ($case->docType === null || !$case->expectedRetrieval->guidelineRetrievalRequired)) {
            return sprintf('Case "%s" has combined_document_guideline tag without both document input and required guideline retrieval.', $case->caseId);
        }
        if ($tag === 'wrong_document_retraction' && $case->expectedRubrics->expectedFor('deleted_document_not_retrieved') !== true) {
            return sprintf('Case "%s" has wrong_document_retraction tag without deleted_document_not_retrieved rubric.', $case->caseId);
        }
        if ($tag === 'duplicate_upload' && $case->category->value !== 'duplicate_upload') {
            return sprintf('Case "%s" has duplicate_upload tag outside duplicate_upload category.', $case->caseId);
        }

        return null;
    }

    /** @param list<EvalCase> $cases */
    private function hasExpectedPromotions(array $cases): bool
    {
        foreach ($cases as $case) {
            if ($case->expectedPromotions !== []) {
                return true;
            }
        }

        return false;
    }

    /** @param list<EvalCase> $cases */
    private function hasExpectedDocumentFacts(array $cases): bool
    {
        foreach ($cases as $case) {
            if ($case->expectedDocumentFacts !== []) {
                return true;
            }
        }

        return false;
    }
}
