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

final class CheckClinicalDocumentGateScriptShapeTest extends TestCase
{
    public function testGateScriptContainsRequiredStepsAndParses(): void
    {
        $repo = dirname(__DIR__, 7);
        $script = $repo . '/agent-forge/scripts/check-clinical-document.sh';
        $contents = file_get_contents($script);

        $this->assertIsString($contents);
        $this->assertStringContainsString('git diff --check', $contents);
        $this->assertStringContainsString('composer phpunit-isolated', $contents);
        $this->assertStringContainsString('php agent-forge/scripts/run-clinical-document-evals.php', $contents);
        $this->assertStringContainsString('composer phpstan', $contents);

        $process = new Process(['bash', '-n', $script], $repo);
        $process->run();
        $this->assertSame(0, $process->getExitCode(), $process->getErrorOutput());
    }
}
