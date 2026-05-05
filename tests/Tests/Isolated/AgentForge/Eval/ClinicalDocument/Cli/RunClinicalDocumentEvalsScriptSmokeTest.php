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
