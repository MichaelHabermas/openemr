<?php

/**
 * Isolated tests for readiness aggregation behavior.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Tests\Isolated\AgentForge\Health;

use OpenEMR\Health\HealthChecker;
use OpenEMR\Health\HealthCheckInterface;
use OpenEMR\Health\HealthCheckResult;
use PHPUnit\Framework\TestCase;

final class HealthCheckerRuntimeStatusTest extends TestCase
{
    public function testFailedCheckMakesTopLevelStatusError(): void
    {
        $checker = new HealthChecker();
        $reflection = new \ReflectionProperty(HealthChecker::class, 'checks');
        $reflection->setValue($checker, [
            new FixedHealthCheck('installed', true),
            new FixedHealthCheck('agentforge_runtime', false, ['status' => 'unhealthy']),
        ]);

        $payload = $checker->getResultsArray();
        $components = $payload['components'] ?? [];
        $runtime = $components['agentforge_runtime'] ?? [];
        $this->assertIsArray($runtime);

        $this->assertSame('error', $payload['status']);
        $this->assertFalse($payload['checks']['agentforge_runtime']);
        $this->assertSame('unhealthy', $runtime['status'] ?? null);
    }
}

final readonly class FixedHealthCheck implements HealthCheckInterface
{
    /**
     * @param array<string, mixed> $details
     */
    public function __construct(
        private string $name,
        private bool $healthy,
        private array $details = [],
    ) {
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function check(): HealthCheckResult
    {
        return new HealthCheckResult($this->name, $this->healthy, null, $this->details);
    }
}
