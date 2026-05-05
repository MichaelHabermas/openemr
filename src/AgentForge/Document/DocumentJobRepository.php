<?php

/**
 * Persistence boundary for AgentForge document extraction jobs.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\AgentForge\Document;

use OpenEMR\AgentForge\Auth\PatientId;

interface DocumentJobRepository
{
    public function enqueue(PatientId $patientId, DocumentId $documentId, DocumentType $docType): DocumentJobId;

    public function retractByDocument(DocumentId $documentId, DocumentRetractionReason $reason): int;

    public function findById(DocumentJobId $id): ?DocumentJob;

    public function findOneByUniqueKey(
        PatientId $patientId,
        DocumentId $documentId,
        DocumentType $docType,
    ): ?DocumentJob;
}
