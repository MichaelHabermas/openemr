<?php

/**
 * Script and Docker shape tests for the AgentForge document worker.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Tests\Isolated\AgentForge\Document\Worker;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

final class ProcessDocumentJobsScriptShapeTest extends TestCase
{
    public function testScriptParsesAsPhp(): void
    {
        $repo = dirname(__DIR__, 6);
        $process = new Process(['php', '-l', 'agent-forge/scripts/process-document-jobs.php'], $repo);
        $process->run();

        $this->assertSame(0, $process->getExitCode(), $process->getOutput() . $process->getErrorOutput());
    }

    public function testDevelopmentEasyComposeDefinesAgentForgeWorkerService(): void
    {
        $compose = file_get_contents(dirname(__DIR__, 6) . '/docker/development-easy/docker-compose.yml');
        $this->assertIsString($compose);

        $this->assertStringContainsString('agentforge-worker:', $compose);
        $this->assertStringContainsString('process-document-jobs.php', $compose);
        $this->assertStringContainsString('trap', $compose);
        $this->assertStringContainsString('--mark-stopped', $compose);
        $this->assertStringContainsString('AGENTFORGE_WORKER_IDLE_SLEEP_SECONDS', $compose);
        $this->assertStringContainsString('openemr:', $compose);
        $this->assertStringContainsString('condition: service_healthy', $compose);
    }
}
