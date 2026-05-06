<?php

/**
 * Script-shape tests for H3 deployment runtime proof.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Tests\Isolated\AgentForge;

use PHPUnit\Framework\TestCase;

final class DeploymentRuntimeScriptTest extends TestCase
{
    public function testHealthCheckValidatesAgentForgeRuntimeComponents(): void
    {
        $script = file_get_contents(dirname(__DIR__, 4) . '/agent-forge/scripts/health-check.sh');
        $this->assertIsString($script);

        $this->assertStringContainsString('validate_readyz_payload', $script);
        $this->assertStringContainsString('agentforge_runtime', $script);
        $this->assertStringContainsString('MariaDB 11.8', $script);
        $this->assertStringContainsString('agentforge-worker heartbeat check failed', $script);
        $this->assertStringContainsString('clinical document queue check failed', $script);
        $this->assertStringContainsString('"required"', $script);
    }

    public function testDeployAndRollbackWaitForFullHealthAfterComposeAndSeed(): void
    {
        $root = dirname(__DIR__, 4);
        $deploy = file_get_contents($root . '/agent-forge/scripts/deploy-vm.sh');
        $rollback = file_get_contents($root . '/agent-forge/scripts/rollback-vm.sh');
        $this->assertIsString($deploy);
        $this->assertIsString($rollback);

        $this->assertStringContainsString('docker compose up -d mysql openemr agentforge-worker', $deploy);
        $this->assertStringContainsString('compose_up_runtime', $rollback);
        $this->assertStringContainsString("grep -qx 'agentforge-worker'", $rollback);

        foreach ([$deploy, $rollback] as $script) {
            $this->assertGreaterThanOrEqual(2, substr_count($script, 'wait_for_health'));
            $this->assertStringContainsString('health-check.sh', $script);
        }
    }
}
