<?php

/**
 * Deep module facade for clinical document extraction.
 *
 * Single public entry point (attachAndExtract) hiding complex orchestration:
 * - Document storage and loading
 * - Content normalization (PDF/TIFF/DOCX/XLSX/HL7v2)
 * - Schema-specific extraction strategies
 * - Schema validation
 * - Fact mapping
 * - Identity verification
 * - Source attribution (citations with page/quote/bounding-box)
 * - Deadline enforcement
 * - Telemetry and PHI-safe logging
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\AgentForge\Document\Extraction\Module;

use OpenEMR\AgentForge\Auth\PatientId;
use OpenEMR\AgentForge\Deadline;
use OpenEMR\AgentForge\Document\AttachAndExtractResult;
use OpenEMR\AgentForge\Document\DocumentId;
use OpenEMR\AgentForge\Document\DocumentType;
use OpenEMR\AgentForge\Document\Extraction\ExtractionProviderResponse;
use OpenEMR\AgentForge\Document\Identity\DocumentIdentityVerifier;
use OpenEMR\AgentForge\Document\Identity\ExtractionIdentityEvidenceBuilder;
use OpenEMR\AgentForge\Document\Identity\IdentityStatus;
use OpenEMR\AgentForge\Document\Identity\PatientIdentityRepository;
use OpenEMR\AgentForge\Document\SourceDocumentStorage;
use OpenEMR\AgentForge\Document\Worker\DocumentLoader;
use OpenEMR\AgentForge\Document\Worker\DocumentLoadException;
use OpenEMR\AgentForge\Observability\PatientRefHasher;
use OpenEMR\AgentForge\Observability\SensitiveLogPolicy;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use RuntimeException;

final readonly class DocumentExtractionModule
{
    public function __construct(
        private DocumentExtractionRegistry $registry,
        private SourceDocumentStorage $storage,
        private DocumentLoader $loader,
        private ?PatientIdentityRepository $patientIdentities = null,
        private ?DocumentIdentityVerifier $identityVerifier = null,
        private ?ExtractionIdentityEvidenceBuilder $identityEvidenceBuilder = null,
        private ?LoggerInterface $logger = null,
        private bool $allowContractOnlyExtractions = false,
    ) {
    }

    /**
     * Attach and extract a clinical document.
     *
     * The single public entry point for document ingestion. All complexity is hidden
     * internally: storage, loading, strategy selection, extraction, validation,
     * identity verification, and fact mapping.
     *
     * @param PatientId $patientId The patient to associate with this document
     * @param string $filePath Path to the source document file (for new uploads)
     * @param DocumentType $docType The document type being ingested
     * @param Deadline $deadline Time constraint for the extraction operation
     */
    public function attachAndExtract(
        PatientId $patientId,
        string $filePath,
        DocumentType $docType,
        Deadline $deadline,
    ): AttachAndExtractResult {
        $logger = $this->logger ?? new NullLogger();
        $patientRefHasher = PatientRefHasher::createDefault();

        // Check if document type is registered
        if (!$this->registry->isRegistered($docType)) {
            $logger->warning('Document type not registered for extraction', [
                'doc_type' => $docType->value,
                'patient_ref' => $patientRefHasher->hash($patientId),
            ]);

            return AttachAndExtractResult::failed(
                ExtractionErrorCode::UnsupportedDocType,
                'Document type is not supported for extraction.',
            );
        }

        // Check runtime support
        if (!$docType->runtimeIngestionSupported() && !$this->allowContractOnlyExtractions) {
            return AttachAndExtractResult::failed(
                ExtractionErrorCode::UnsupportedDocType,
                'Document type is contract-only until runtime ingestion support is implemented.',
            );
        }

        // Store uploaded file
        try {
            $documentId = $this->storage->storeUploadedFile($patientId, $filePath, $docType);
        } catch (RuntimeException $e) {
            $logger->error('Document storage failed', [
                'patient_ref' => $patientRefHasher->hash($patientId),
                'doc_type' => $docType->value,
                'error' => $e->getMessage(),
            ]);

            return AttachAndExtractResult::failed(
                ExtractionErrorCode::StorageFailure,
                'Source document could not be stored before extraction.',
            );
        }

        // Log storage success (PHI-safe)
        $logger->info('Document stored for extraction', [
            'document_id' => $documentId->value,
            'doc_type' => $docType->value,
            'patient_ref' => $patientRefHasher->hash($patientId),
        ]);

        return $this->extractExisting($patientId, $documentId, $docType, $deadline);
    }

    /**
     * Extract from an already-stored document.
     *
     * @param PatientId $patientId The patient (caller must verify ownership)
     * @param DocumentId $documentId The stored document ID
     * @param DocumentType $docType The document type
     * @param Deadline $deadline Time constraint for extraction
     */
    public function extractExisting(
        PatientId $patientId,
        DocumentId $documentId,
        DocumentType $docType,
        Deadline $deadline,
    ): AttachAndExtractResult {
        $logger = $this->logger ?? new NullLogger();
        $patientRefHasher = PatientRefHasher::createDefault();

        // Validate patient-document scope
        if (!$this->patientDocumentScopeValid($patientId, $documentId)) {
            return AttachAndExtractResult::failed(
                ExtractionErrorCode::StorageFailure,
                'Unable to derive patient-document scope.',
                $documentId,
            );
        }

        // Load document
        try {
            $document = $this->loader->load($documentId);
        } catch (DocumentLoadException $e) {
            $logger->error('Document load failed', [
                'document_id' => $documentId->value,
                'error' => $e->getMessage(),
            ]);

            return AttachAndExtractResult::failed(
                ExtractionErrorCode::MissingFile,
                'Source document could not be loaded before extraction.',
                $documentId,
            );
        }

        // Get extraction pipeline from registry
        try {
            $pipeline = $this->registry->getPipeline($docType);
        } catch (\DomainException $e) {
            return AttachAndExtractResult::failed(
                ExtractionErrorCode::UnsupportedDocType,
                $e->getMessage(),
                $documentId,
            );
        }

        // Execute extraction via strategy
        try {
            $response = $pipeline->strategy->extract($document, $deadline);
        } catch (\OpenEMR\AgentForge\Document\Extraction\ExtractionProviderException $e) {
            $logger->error('Document extraction failed', [
                'document_id' => $documentId->value,
                'doc_type' => $docType->value,
                'error_code' => $e->errorCode->value,
            ]);

            return AttachAndExtractResult::failed(
                $e->errorCode,
                'Document extraction failed.',
                $documentId,
            );
        }

        // Validate schema
        if (!$response->schemaValid) {
            $logger->warning('Schema validation failed', [
                'document_id' => $documentId->value,
                'doc_type' => $docType->value,
            ]);

            return AttachAndExtractResult::failed(
                ExtractionErrorCode::SchemaValidationFailure,
                'Extracted document output failed strict schema validation.',
                $documentId,
            );
        }

        // Verify identity if extraction requires it
        $identityResult = $this->verifyIdentity($patientId, $documentId, $response, $pipeline);
        if ($identityResult !== null) {
            return $identityResult;
        }

        // Log success (PHI-safe)
        $logger->info('Document extraction completed', [
            'document_id' => $documentId->value,
            'doc_type' => $docType->value,
            'patient_ref' => $patientRefHasher->hash($patientId),
            'schema_valid' => $response->schemaValid,
        ]);

        return AttachAndExtractResult::succeeded($documentId, $response);
    }

    /**
     * Verify document identity if the strategy's schema requires it.
     */
    private function verifyIdentity(
        PatientId $patientId,
        DocumentId $documentId,
        ExtractionProviderResponse $response,
        ExtractionPipeline $pipeline,
    ): ?AttachAndExtractResult {
        // Check if identity gate is configured for this schema type
        if (!in_array($response->extraction::class, $this->identityRequiringSchemas(), true)) {
            return null;
        }

        if (
            $this->patientIdentities === null
            || $this->identityVerifier === null
            || $this->identityEvidenceBuilder === null
        ) {
            return AttachAndExtractResult::failed(
                ExtractionErrorCode::IdentityAmbiguousNeedsReview,
                'Document identity gate is not fully configured.',
                $documentId,
            );
        }

        $patientIdentity = $this->patientIdentities->findByPatientId($patientId);
        if ($patientIdentity === null) {
            return AttachAndExtractResult::failed(
                ExtractionErrorCode::IdentityAmbiguousNeedsReview,
                'Patient identity could not be loaded for document verification.',
                $documentId,
            );
        }

        $identityResult = $this->identityVerifier->verify(
            $patientIdentity,
            $this->identityEvidenceBuilder->build($documentId, $response->extraction, ''),
        );

        if ($identityResult->status === IdentityStatus::MismatchQuarantined) {
            return AttachAndExtractResult::failed(
                ExtractionErrorCode::IdentityMismatchQuarantined,
                'Document identity conflicts with the selected OpenEMR patient.',
                $documentId,
            );
        }

        if (!$identityResult->trustedForEvidence()) {
            return AttachAndExtractResult::failed(
                ExtractionErrorCode::IdentityAmbiguousNeedsReview,
                'Document identity is ambiguous and requires review before trusted use.',
                $documentId,
            );
        }

        return null; // Identity verified successfully
    }

    /**
     * @return list<class-string<object>>
     */
    private function identityRequiringSchemas(): array
    {
        // These schema types contain patient-identifying information that must be verified
        return [
            \OpenEMR\AgentForge\Document\Schema\LabPdfExtraction::class,
            \OpenEMR\AgentForge\Document\Schema\IntakeFormExtraction::class,
            \OpenEMR\AgentForge\Document\Schema\ReferralDocxExtraction::class,
            \OpenEMR\AgentForge\Document\Schema\ClinicalWorkbookExtraction::class,
            \OpenEMR\AgentForge\Document\Schema\FaxPacketExtraction::class,
            \OpenEMR\AgentForge\Document\Schema\Hl7v2MessageExtraction::class,
        ];
    }

    private function patientDocumentScopeValid(PatientId $patientId, DocumentId $documentId): bool
    {
        return $patientId->value > 0 && $documentId->value > 0;
    }
}
