<?php

/**
 * SQL-backed AgentForge document job claimer.
 *
 * Claims the oldest {@see JobStatus::Pending} extraction row for the spec-required intake-extractor.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\AgentForge\Document\Worker;

use OpenEMR\AgentForge\DatabaseExecutor;
use OpenEMR\AgentForge\Document\DocumentJob;
use OpenEMR\AgentForge\Document\JobStatus;

final readonly class SqlJobClaimer implements JobClaimer
{
    public function __construct(
        private DocumentJobWorkerRepository $jobs,
        private DatabaseExecutor $executor,
    ) {
    }

    public function claimNext(WorkerName $workerName, LockToken $lockToken): ?DocumentJob
    {
        if ($workerName !== WorkerName::IntakeExtractor) {
            return null;
        }

        $affected = $this->executor->executeAffected(
            'UPDATE clinical_document_processing_jobs '
            . 'SET status = ?, lock_token = ?, started_at = NOW(), attempts = attempts + 1 '
            . 'WHERE status = ? AND retracted_at IS NULL '
            . 'ORDER BY created_at ASC '
            . 'LIMIT 1',
            [JobStatus::Running->value, $lockToken->value, JobStatus::Pending->value],
        );

        if ($affected === 0) {
            return null;
        }

        return $this->jobs->findClaimedByLockToken($lockToken);
    }
}
