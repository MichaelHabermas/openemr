<?php

/**
 * Decides whether an uploaded OpenEMR document should create an AgentForge job.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\AgentForge\Document;

use DomainException;
use InvalidArgumentException;
use OpenEMR\AgentForge\Auth\PatientId;
use OpenEMR\AgentForge\Observability\PatientRefHasher;
use OpenEMR\AgentForge\Observability\SensitiveLogPolicy;
use Psr\Log\LoggerInterface;
use RuntimeException;

final readonly class DocumentUploadEnqueuer
{
    public function __construct(
        private DocumentTypeMappingRepository $mappings,
        private DocumentJobRepository $jobs,
        private LoggerInterface $logger,
        private PatientRefHasher $patientRefHasher,
    ) {
    }

    public function enqueueIfEligible(
        PatientId $patientId,
        DocumentId $documentId,
        CategoryId $categoryId,
    ): ?DocumentJobId {
        try {
            $mapping = $this->mappings->findActiveByCategoryId($categoryId);
            if ($mapping === null) {
                return null;
            }

            $jobId = $this->jobs->enqueue($patientId, $documentId, $mapping->docType);
            $this->logger->info(
                'clinical_document.job.enqueued',
                SensitiveLogPolicy::sanitizeContext([
                    'patient_ref' => $this->patientRefHasher->hash($patientId),
                    'document_id' => $documentId->value,
                    'category_id' => $categoryId->value,
                    'doc_type' => $mapping->docType->value,
                    'job_id' => $jobId->value,
                ]),
            );

            return $jobId;
        } catch (RuntimeException | DomainException | InvalidArgumentException $e) {
            $this->logger->error(
                'clinical_document.job.enqueue_failed',
                SensitiveLogPolicy::throwableErrorContext($e, [
                    'document_id' => $documentId->value,
                    'category_id' => $categoryId->value,
                ]),
            );

            return null;
        }
    }
}
