<?php

/**
 * Persistence boundary for AgentForge document worker heartbeats.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\AgentForge\Document\Worker;

interface WorkerHeartbeatRepository
{
    public function upsert(WorkerHeartbeat $heartbeat): void;

    public function findByWorker(WorkerName $workerName): ?WorkerHeartbeat;
}
