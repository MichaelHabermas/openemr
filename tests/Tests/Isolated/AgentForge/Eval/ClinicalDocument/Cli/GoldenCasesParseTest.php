<?php

/**
 * Isolated tests for AgentForge clinical document eval support.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Tests\Isolated\AgentForge\Eval\ClinicalDocument\Cli;

use OpenEMR\AgentForge\Eval\ClinicalDocument\Case\EvalCaseLoader;
use OpenEMR\AgentForge\Eval\ClinicalDocument\Rubric\RubricRegistry;
use OpenEMR\AgentForge\Eval\ClinicalDocument\Runner\StructuralCoveragePolicy;
use PHPUnit\Framework\TestCase;

final class GoldenCasesParseTest extends TestCase
{
    public function testEveryGoldenCaseParses(): void
    {
        $repo = dirname(__DIR__, 7);
        $cases = (new EvalCaseLoader())->loadDirectory($repo . '/agent-forge/fixtures/clinical-document-golden/cases');

        $this->assertGreaterThanOrEqual(50, count($cases));
        $this->assertLessThanOrEqual(80, count($cases));
        $caseIds = array_map(static fn ($case): string => $case->caseId, $cases);
        $this->assertContains('chen-intake-typed', $caseIds);
    }

    public function testGoldenCasesSatisfyStructuralCoveragePolicy(): void
    {
        $repo = dirname(__DIR__, 7);
        $cases = (new EvalCaseLoader())->loadDirectory($repo . '/agent-forge/fixtures/clinical-document-golden/cases');

        $violations = (new StructuralCoveragePolicy())->validate($cases, new RubricRegistry());

        $this->assertSame([], $violations);
    }
}
