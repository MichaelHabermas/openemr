<?php

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
