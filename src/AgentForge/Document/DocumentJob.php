<?php

/**
 * AgentForge document extraction job DTO.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\AgentForge\Document;

use DateTimeImmutable;
use OpenEMR\AgentForge\Auth\PatientId;

final readonly class DocumentJob
{
    public function __construct(
        public ?DocumentJobId $id,
        public PatientId $patientId,
        public DocumentId $documentId,
        public DocumentType $docType,
        public JobStatus $status,
        public int $attempts,
        public ?string $lockToken,
        public DateTimeImmutable $createdAt,
        public ?DateTimeImmutable $startedAt,
        public ?DateTimeImmutable $finishedAt,
        public ?string $errorCode,
        public ?string $errorMessage,
        public ?DateTimeImmutable $retractedAt,
        public ?DocumentRetractionReason $retractionReason,
    ) {
    }
}
