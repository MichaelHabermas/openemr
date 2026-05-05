<?php

declare(strict_types=1);

namespace OpenEMR\Tests\Isolated\AgentForge\Eval\ClinicalDocument\Cli;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

final class RunClinicalDocumentEvalsScriptSmokeTest extends TestCase
{
    public function testScriptRunsAndFailsForMissingImplementation(): void
    {
        $repo = dirname(__DIR__, 7);
        $resultsDir = sys_get_temp_dir() . '/clinical-document-eval-smoke-' . bin2hex(random_bytes(4));
        $process = new Process(['php', 'agent-forge/scripts/run-clinical-document-evals.php'], $repo, [
            'AGENTFORGE_CLINICAL_DOCUMENT_EVAL_RESULTS_DIR' => $resultsDir,
        ]);
        $process->run();

        $this->assertSame(2, $process->getExitCode(), $process->getOutput() . $process->getErrorOutput());
        $this->assertStringContainsString('threshold_violation', $process->getOutput());
        $this->assertNotFalse(glob($resultsDir . '/clinical-document-*/run.json'));
    }
}
