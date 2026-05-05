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
use PHPUnit\Framework\TestCase;

final class GoldenCasesParseTest extends TestCase
{
    public function testEveryGoldenCaseParses(): void
    {
        $repo = dirname(__DIR__, 7);
        $cases = (new EvalCaseLoader())->loadDirectory($repo . '/agent-forge/fixtures/clinical-document-golden/cases');

        $this->assertCount(8, $cases);
        $this->assertSame('chen-intake-typed', $cases[0]->caseId);
    }
}
