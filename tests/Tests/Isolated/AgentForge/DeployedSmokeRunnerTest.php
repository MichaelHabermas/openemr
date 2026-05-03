<?php

/**
 * Isolated tests for AgentForge deployed smoke runner helpers.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Tests\Isolated\AgentForge;

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 4) . '/agent-forge/scripts/lib/deployed-smoke-runner.php';

final class DeployedSmokeRunnerTest extends TestCase
{
    /** @var array<string, string|false> */
    private array $priorEnv = [];

    private string $priorCwd;

    protected function setUp(): void
    {
        parent::setUp();

        $this->priorCwd = getcwd() ?: dirname(__DIR__, 4);
        foreach (['AGENTFORGE_COMPOSE_FILE', 'AGENTFORGE_COMPOSE_DIR'] as $name) {
            $this->priorEnv[$name] = getenv($name);
            putenv($name);
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->priorEnv as $name => $value) {
            if ($value === false) {
                putenv($name);
            } else {
                putenv($name . '=' . $value);
            }
        }

        chdir($this->priorCwd);

        parent::tearDown();
    }

    public function testComposeFilePathIsRepoAnchoredWhenCallerUsesDifferentCwd(): void
    {
        chdir(sys_get_temp_dir());

        $this->assertSame(
            dirname(__DIR__, 4) . '/docker/development-easy/docker-compose.yml',
            \agentforge_deployed_smoke_compose_file_path(),
        );
    }

    public function testComposeFilePathAllowsExplicitFileOverride(): void
    {
        putenv('AGENTFORGE_COMPOSE_FILE=/tmp/agentforge-compose.yml');

        $this->assertSame('/tmp/agentforge-compose.yml', \agentforge_deployed_smoke_compose_file_path());
    }

    public function testAuditExpectationAllowsEmptyStringForNoFailureReason(): void
    {
        $result = \agentforge_deployed_smoke_evaluate_audit_log(
            [
                'found' => true,
                'fields' => [
                    'user_id' => 7,
                    'patient_id' => 900001,
                    'decision' => 'allowed',
                    'latency_ms' => 1234,
                    'model' => 'gpt-5.4-mini',
                    'verifier_result' => 'passed',
                    'failure_reason' => '',
                ],
                'raw_line' => '{"failure_reason":""}',
                'error' => null,
            ],
            [
                'verifier_result' => 'passed',
                'failure_reason' => ['_one_of' => [null, '']],
                'model' => ['_not_one_of' => ['fixture-draft-provider', 'not_run', 'disabled-draft-provider']],
            ],
        );

        $this->assertTrue($result['expected_match']);
        $this->assertSame([], $result['issues']);
    }
}
