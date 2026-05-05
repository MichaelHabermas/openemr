<?php

/**
 * Worker-only persistence methods for claimed document jobs.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\AgentForge\Document\Worker;

use OpenEMR\AgentForge\Document\DocumentJob;
use OpenEMR\AgentForge\Document\DocumentJobId;
use OpenEMR\AgentForge\Document\JobStatus;

interface DocumentJobWorkerRepository
{
    public function markFinished(
        DocumentJobId $id,
        LockToken $lockToken,
        JobStatus $terminal,
        ?string $errorCode,
        ?string $errorMessage,
    ): int;

    public function findClaimedByLockToken(LockToken $lockToken): ?DocumentJob;
}
