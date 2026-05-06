<?php

/**
 * HealthChecker - Runs all health checks and aggregates results
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    Michael A. Smith <michael@opencoreemr.com>
 * @copyright Copyright (c) 2025 OpenCoreEMR Inc <https://opencoreemr.com/>
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

namespace OpenEMR\Health;

use OpenEMR\Health\Check\AgentForgeRuntimeCheck;
use OpenEMR\Health\Check\CacheCheck;
use OpenEMR\Health\Check\DatabaseCheck;
use OpenEMR\Health\Check\FilesystemCheck;
use OpenEMR\Health\Check\InstallationCheck;
use OpenEMR\Health\Check\OAuthKeysCheck;
use OpenEMR\Health\Check\SessionCheck;

class HealthChecker
{
    /** @var HealthCheckInterface[] */
    private array $checks = [];

    public function __construct()
    {
        $this->registerDefaultChecks();
    }

    private function registerDefaultChecks(): void
    {
        $this->addCheck(new InstallationCheck());
        $this->addCheck(new DatabaseCheck());
        $this->addCheck(new FilesystemCheck());
        $this->addCheck(new SessionCheck());
        $this->addCheck(new OAuthKeysCheck());
        $this->addCheck(new CacheCheck());
        $this->addCheck(new AgentForgeRuntimeCheck());
    }

    public function addCheck(HealthCheckInterface $check): void
    {
        $this->checks[] = $check;
    }

    /**
     * Run all health checks
     *
     * @return HealthCheckResult[]
     */
    public function runAll(): array
    {
        $results = [];
        foreach ($this->checks as $check) {
            $results[] = $check->check();
        }
        return $results;
    }

    /**
     * Get results as an associative array suitable for JSON response
     *
     * @return array{status: string, checks: array<string, bool>, components?: array<string, mixed>}
     */
    public function getResultsArray(): array
    {
        $checks = [];
        $components = [];
        $isInstalled = true;
        $allHealthy = true;

        foreach ($this->runAll() as $result) {
            $checks[$result->name] = $result->healthy;
            if (!$result->healthy) {
                $allHealthy = false;
            }
            if ($result->details !== []) {
                $components[$result->name] = $result->details;
            }
            if ($result->name === InstallationCheck::NAME && !$result->healthy) {
                $isInstalled = false;
            }
        }

        $payload = [
            'status' => $isInstalled ? ($allHealthy ? 'ready' : 'error') : 'setup_required',
            'checks' => $checks,
        ];
        if ($components !== []) {
            $payload['components'] = $components;
        }

        return $payload;
    }
}
