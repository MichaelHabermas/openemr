<?php

/**
 * Isolated tests for the AgentForge Week 2 runtime health contract.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Tests\Isolated\AgentForge\Health;

use OpenEMR\Health\Check\AgentForgeRuntimeCheck;
use PHPUnit\Framework\TestCase;

final class AgentForgeRuntimeCheckTest extends TestCase
{
    public function testMariaDbVersionRequiresElevenEight(): void
    {
        $this->assertTrue(AgentForgeRuntimeCheck::evaluateMariaDb('11.8.6-MariaDB')['healthy']);
        $this->assertTrue(AgentForgeRuntimeCheck::evaluateMariaDb('11.9.0-MariaDB')['healthy']);
        $this->assertTrue(AgentForgeRuntimeCheck::evaluateMariaDb('12.0.0-MariaDB')['healthy']);
        $this->assertFalse(AgentForgeRuntimeCheck::evaluateMariaDb('11.7.2-MariaDB')['healthy']);
        $this->assertFalse(AgentForgeRuntimeCheck::evaluateMariaDb('10.11.0-MariaDB')['healthy']);
    }

    public function testWorkerHeartbeatMustExistBeFreshAndNotStopped(): void
    {
        $check = new AgentForgeRuntimeCheck();

        $this->assertFalse($check->evaluateWorkerHeartbeat(null)['healthy']);
        $this->assertFalse($check->evaluateWorkerHeartbeat([
            'worker' => 'intake-extractor',
            'status' => 'stopped',
            'last_heartbeat_at' => gmdate('Y-m-d H:i:s'),
            'stopped_at' => gmdate('Y-m-d H:i:s'),
        ])['healthy']);
        $this->assertFalse($check->evaluateWorkerHeartbeat([
            'worker' => 'intake-extractor',
            'status' => 'running',
            'last_heartbeat_at' => gmdate('Y-m-d H:i:s', time() - 500),
            'last_heartbeat_age_seconds' => 500,
            'stopped_at' => null,
        ])['healthy']);
        $this->assertTrue($check->evaluateWorkerHeartbeat([
            'worker' => 'intake-extractor',
            'status' => 'idle',
            'last_heartbeat_at' => gmdate('Y-m-d H:i:s', time() - 5),
            'last_heartbeat_age_seconds' => 5,
            'stopped_at' => null,
            'jobs_processed' => 2,
            'jobs_failed' => 0,
        ])['healthy']);
    }

    public function testQueueFailsOnlyForStaleOperationalBacklog(): void
    {
        $check = new AgentForgeRuntimeCheck();

        $this->assertTrue($check->evaluateQueue([
            'pending' => 0,
            'running' => 0,
            'failed_recent' => 0,
            'oldest_pending_age_seconds' => null,
            'stale_running' => 0,
        ])['healthy']);
        $this->assertFalse($check->evaluateQueue([
            'pending' => 0,
            'running' => 0,
            'failed_recent' => 2,
            'oldest_pending_age_seconds' => null,
            'stale_running' => 0,
        ])['failed_recent_affects_health']);
        $this->assertFalse($check->evaluateQueue([
            'pending' => 1,
            'running' => 0,
            'failed_recent' => 0,
            'oldest_pending_age_seconds' => 900,
            'stale_running' => 0,
        ])['healthy']);
        $this->assertFalse($check->evaluateQueue([
            'pending' => 0,
            'running' => 1,
            'failed_recent' => 0,
            'oldest_pending_age_seconds' => null,
            'stale_running' => 1,
        ])['healthy']);
    }

    public function testDetailsDoNotExposeClinicalIdentifiersOrContentKeys(): void
    {
        $check = new AgentForgeRuntimeCheck();
        $payload = [
            'mariadb' => AgentForgeRuntimeCheck::evaluateMariaDb('11.8.6-MariaDB'),
            'worker' => $check->evaluateWorkerHeartbeat([
                'worker' => 'intake-extractor',
                'status' => 'running',
                'last_heartbeat_at' => gmdate('Y-m-d H:i:s'),
                'last_heartbeat_age_seconds' => 0,
                'stopped_at' => null,
            ]),
            'queue' => $check->evaluateQueue([]),
        ];
        $encoded = json_encode($payload, JSON_THROW_ON_ERROR);

        foreach (['patient_id', 'patient_ref', 'document_id', 'job_id', 'filename', 'quote', 'raw_value', 'document_text', 'exception'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $encoded);
        }
    }
}
