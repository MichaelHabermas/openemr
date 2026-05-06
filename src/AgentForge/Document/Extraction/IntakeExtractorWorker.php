<?php

/**
 * Document job processor for strict clinical document extraction.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\AgentForge\Document\Extraction;

use OpenEMR\AgentForge\Deadline;
use OpenEMR\AgentForge\Document\DocumentJob;
use OpenEMR\AgentForge\Document\Identity\DocumentIdentityCheckRepository;
use OpenEMR\AgentForge\Document\Identity\DocumentIdentityVerifier;
use OpenEMR\AgentForge\Document\Identity\ExtractionIdentityEvidenceBuilder;
use OpenEMR\AgentForge\Document\Identity\IdentityMatchResult;
use OpenEMR\AgentForge\Document\Identity\IdentityStatus;
use OpenEMR\AgentForge\Document\Identity\PatientIdentityRepository;
use OpenEMR\AgentForge\Document\Promotion\ClinicalDocumentFactPromotionRepository;
use OpenEMR\AgentForge\Document\Schema\CertaintyClassifier;
use OpenEMR\AgentForge\Document\Schema\IntakeFormExtraction;
use OpenEMR\AgentForge\Document\Schema\LabPdfExtraction;
use OpenEMR\AgentForge\Document\Worker\DocumentJobProcessor;
use OpenEMR\AgentForge\Document\Worker\DocumentLoadResult;
use OpenEMR\AgentForge\Document\Worker\ProcessingResult;
use OpenEMR\AgentForge\Observability\DocumentExtractionLogContext;
use OpenEMR\AgentForge\Orchestration\NodeName;
use OpenEMR\AgentForge\Observability\PatientRefHasher;
use OpenEMR\AgentForge\Time\MonotonicClock;
use Psr\Log\LoggerInterface;

final readonly class IntakeExtractorWorker implements DocumentJobProcessor
{
    public function __construct(
        private DocumentExtractionProvider $provider,
        private CertaintyClassifier $classifier,
        private LoggerInterface $logger,
        private MonotonicClock $clock,
        private PatientRefHasher $patientRefHasher,
        private int $budgetMs = 60_000,
        private ?PatientIdentityRepository $patientIdentities = null,
        private ?DocumentIdentityCheckRepository $identityChecks = null,
        private ?DocumentIdentityVerifier $identityVerifier = null,
        private ?ExtractionIdentityEvidenceBuilder $identityEvidenceBuilder = null,
        private ?ClinicalDocumentFactPromotionRepository $factPromotions = null,
    ) {
    }

    public function process(DocumentJob $job, DocumentLoadResult $document): ProcessingResult
    {
        // The spec-required worker name is "intake-extractor"; this worker handles lab_pdf and intake_form.
        try {
            $response = $this->provider->extract(
                $job->documentId,
                $document,
                $job->docType,
                new Deadline($this->clock, $this->budgetMs),
            );
        } catch (ExtractionProviderException $e) {
            return ProcessingResult::failed($e->errorCode->value, $e->getMessage());
        }

        if (!$response->schemaValid) {
            return ProcessingResult::failed('schema_validation_failure', 'Extraction provider returned schema-invalid output.');
        }

        if (!$response->extraction instanceof LabPdfExtraction && !$response->extraction instanceof IntakeFormExtraction) {
            return ProcessingResult::failed('schema_validation_failure', 'Extraction provider returned schema-invalid output.');
        }

        $identityResult = $this->verifyIdentity($job, $document, $response->extraction);
        if ($identityResult instanceof ProcessingResult) {
            return $identityResult;
        }

        $counts = $this->countFactBuckets($job, $response->extraction);
        $promotion = $this->factPromotions?->promote($job, $response->extraction);
        $this->logger->info(
            'document.extraction.completed',
            DocumentExtractionLogContext::intakeExtractionCompleted(
                NodeName::IntakeExtractor,
                $job,
                $response,
                $this->patientRefHasher,
                $counts,
            ),
        );
        if ($promotion !== null) {
            $this->logger->info('document.extraction.promoted', [
                'worker' => NodeName::IntakeExtractor->value,
                'job_id' => $job->id?->value,
                'document_id' => $job->documentId->value,
                'promoted' => $promotion->promoted,
                'needs_review' => $promotion->needsReview,
                'skipped' => $promotion->skipped,
            ]);
        }

        return ProcessingResult::succeeded();
    }

    private function verifyIdentity(
        DocumentJob $job,
        DocumentLoadResult $document,
        LabPdfExtraction | IntakeFormExtraction $extraction,
    ): ?ProcessingResult {
        if (
            $job->id === null
            || $this->patientIdentities === null
            || $this->identityChecks === null
            || $this->identityVerifier === null
            || $this->identityEvidenceBuilder === null
        ) {
            return ProcessingResult::failed(
                'identity_ambiguous_needs_review',
                'Document identity gate is not fully configured.',
            );
        }

        $patientIdentity = $this->patientIdentities->findByPatientId($job->patientId);
        if ($patientIdentity === null) {
            $this->identityChecks->saveResult(
                $job->patientId,
                $job->documentId,
                $job->id,
                $job->docType,
                new IdentityMatchResult(
                    IdentityStatus::AmbiguousNeedsReview,
                    [],
                    [],
                    'patient_identity_not_found',
                    true,
                ),
            );

            return ProcessingResult::failed('identity_ambiguous_needs_review', 'Patient identity could not be loaded for document verification.');
        }

        $identityResult = $this->identityVerifier->verify(
            $patientIdentity,
            $this->identityEvidenceBuilder->build($job->documentId, $extraction, $document->name),
        );
        $this->identityChecks->saveResult($job->patientId, $job->documentId, $job->id, $job->docType, $identityResult);

        return match ($identityResult->status) {
            IdentityStatus::Verified, IdentityStatus::ReviewApproved => null,
            IdentityStatus::MismatchQuarantined => ProcessingResult::failed(
                'identity_mismatch_quarantined',
                'Document identity conflicts with the selected OpenEMR patient.',
            ),
            default => ProcessingResult::failed(
                'identity_ambiguous_needs_review',
                'Document identity is ambiguous and requires review before trusted use.',
            ),
        };
    }

    /**
     * @return array{verified: int, document_fact: int, needs_review: int}
     */
    private function countFactBuckets(DocumentJob $job, LabPdfExtraction | IntakeFormExtraction $extraction): array
    {
        $counts = [
            'verified' => 0,
            'document_fact' => 0,
            'needs_review' => 0,
        ];

        $candidates = $extraction instanceof LabPdfExtraction ? $extraction->results : $extraction->findings;
        foreach ($candidates as $candidate) {
            ++$counts[$this->classifier->classify($job->docType, $candidate)->value];
        }

        return [
            'verified' => $counts['verified'],
            'document_fact' => $counts['document_fact'],
            'needs_review' => $counts['needs_review'],
        ];
    }
}
