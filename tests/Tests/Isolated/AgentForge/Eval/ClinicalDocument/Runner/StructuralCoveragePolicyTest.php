<?php

/**
 * Isolated tests for AgentForge clinical document eval structural coverage.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Tests\Isolated\AgentForge\Eval\ClinicalDocument\Runner;

use OpenEMR\AgentForge\Eval\ClinicalDocument\Case\EvalCase;
use OpenEMR\AgentForge\Eval\ClinicalDocument\Case\EvalCaseLoader;
use OpenEMR\AgentForge\Eval\ClinicalDocument\Rubric\RubricRegistry;
use OpenEMR\AgentForge\Eval\ClinicalDocument\Runner\StructuralCoveragePolicy;
use PHPUnit\Framework\TestCase;

final class StructuralCoveragePolicyTest extends TestCase
{
    public function testAcceptsCurrentGoldenCaseShape(): void
    {
        $violations = (new StructuralCoveragePolicy())->validate($this->goldenCases(), new RubricRegistry());

        $this->assertSame([], $violations);
    }

    public function testFailsWhenRequiredCategoryIsMissing(): void
    {
        $cases = array_values(array_filter(
            $this->goldenCases(),
            static fn (EvalCase $case): bool => $case->category->value !== 'duplicate_upload',
        ));

        $violations = (new StructuralCoveragePolicy())->validate($cases, new RubricRegistry());

        $this->assertContains('Clinical document golden set must include at least 2 duplicate_upload cases.', $violations);
    }

    public function testFailsWhenRequiredCoverageTagIsMissing(): void
    {
        $cases = array_values(array_filter(
            $this->goldenCases(),
            static fn (EvalCase $case): bool => !in_array('combined_document_guideline', $case->coverageTags, true),
        ));

        $violations = (new StructuralCoveragePolicy())->validate($cases, new RubricRegistry());

        $this->assertContains('Clinical document golden set is missing required H1 coverage tag "combined_document_guideline".', $violations);
    }

    public function testFailsWhenCaseCountExceedsPolicyMaximum(): void
    {
        $cases = $this->goldenCases();
        while (count($cases) <= 60) {
            $cases[] = $cases[0];
        }

        $violations = (new StructuralCoveragePolicy())->validate($cases, new RubricRegistry());

        $this->assertContains(sprintf('Clinical document golden set must contain 50-60 cases; found %d.', count($cases)), $violations);
    }

    /** @return list<EvalCase> */
    private function goldenCases(): array
    {
        $repo = dirname(__DIR__, 7);

        return (new EvalCaseLoader())->loadDirectory($repo . '/agent-forge/fixtures/clinical-document-golden/cases');
    }
}
