<?php

/**
 * Persistence for document identity verification outcomes.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\AgentForge\Document\Identity;

use OpenEMR\AgentForge\Auth\PatientId;
use OpenEMR\AgentForge\Document\DocumentId;
use OpenEMR\AgentForge\Document\DocumentJobId;
use OpenEMR\AgentForge\Document\DocumentType;

interface DocumentIdentityCheckRepository
{
    public function saveResult(
        PatientId $patientId,
        DocumentId $documentId,
        DocumentJobId $jobId,
        DocumentType $docType,
        IdentityMatchResult $result,
    ): void;

    public function trustedForEvidence(DocumentJobId $jobId): bool;
}
