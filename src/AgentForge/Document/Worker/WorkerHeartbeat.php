<?php

/**
 * AgentForge document worker heartbeat DTO.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\AgentForge\Document\Worker;

use DateTimeImmutable;
use DomainException;

final readonly class WorkerHeartbeat
{
    public function __construct(
        public WorkerName $workerName,
        public int $processId,
        public WorkerStatus $status,
        public int $iterationCount,
        public int $jobsProcessed,
        public int $jobsFailed,
        public DateTimeImmutable $startedAt,
        public DateTimeImmutable $lastHeartbeatAt,
        public ?DateTimeImmutable $stoppedAt,
    ) {
        if ($this->processId < 1) {
            throw new DomainException('Worker process id must be positive.');
        }

        foreach ([
            'iteration count' => $this->iterationCount,
            'jobs processed' => $this->jobsProcessed,
            'jobs failed' => $this->jobsFailed,
        ] as $label => $value) {
            if ($value < 0) {
                throw new DomainException("Worker {$label} must be non-negative.");
            }
        }
    }
}
