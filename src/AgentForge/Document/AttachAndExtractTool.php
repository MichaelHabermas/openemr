<?php

/**
 * Spec-required attach_and_extract tool surface for M4.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\AgentForge\Document;

use OpenEMR\AgentForge\Auth\PatientId;
use OpenEMR\AgentForge\Deadline;
use OpenEMR\AgentForge\Document\Extraction\DocumentExtractionProvider;
use OpenEMR\AgentForge\Document\Extraction\ExtractionProviderException;
use OpenEMR\AgentForge\Document\Worker\DocumentLoader;
use OpenEMR\AgentForge\Document\Worker\DocumentLoadException;
use RuntimeException;

final readonly class AttachAndExtractTool
{
    public function __construct(
        private SourceDocumentStorage $storage,
        private DocumentLoader $loader,
        private DocumentExtractionProvider $provider,
    ) {
    }

    /**
     * In-memory storage that also satisfies {@see DocumentLoader} — safe pairing for tests and evals.
     */
    public static function forInMemoryEvalAndTest(
        DocumentExtractionProvider $provider,
        int $firstDocumentId = 1,
    ): self {
        $memory = new InMemorySourceDocumentStorage($firstDocumentId);

        return new self($memory, $memory, $provider);
    }

    public function forUploadedFile(
        PatientId $patientId,
        string $filePath,
        DocumentType $docType,
        Deadline $deadline,
    ): AttachAndExtractResult {
        if (!SourceUploadFile::isReadableNonEmptyFile($filePath)) {
            return AttachAndExtractResult::failed(
                ExtractionErrorCode::MissingFile,
                'Source document file is missing or unreadable.',
            );
        }

        try {
            $documentId = $this->storage->storeUploadedFile($patientId, $filePath, $docType);
        } catch (RuntimeException) {
            return AttachAndExtractResult::failed(
                ExtractionErrorCode::StorageFailure,
                'Source document could not be stored before extraction.',
            );
        }

        return $this->extractExisting($patientId, $documentId, $docType, $deadline);
    }

    /**
     * @param PatientId $patientId Caller must ensure {@see DocumentId} belongs to this patient when using
     *                              OpenEMR-backed loaders; M4 does not re-check patient ownership on load.
     */
    public function forExistingDocument(
        PatientId $patientId,
        DocumentId $documentId,
        DocumentType $docType,
        Deadline $deadline,
    ): AttachAndExtractResult {
        return $this->extractExisting($patientId, $documentId, $docType, $deadline);
    }

    private function extractExisting(
        PatientId $patientId,
        DocumentId $documentId,
        DocumentType $docType,
        Deadline $deadline,
    ): AttachAndExtractResult {
        if (!self::patientDocumentScopeValid($patientId, $documentId)) {
            return AttachAndExtractResult::failed(
                ExtractionErrorCode::StorageFailure,
                'Unable to derive patient-document scope.',
                $documentId,
            );
        }

        try {
            $document = $this->loader->load($documentId);
        } catch (DocumentLoadException) {
            return AttachAndExtractResult::failed(
                ExtractionErrorCode::MissingFile,
                'Source document could not be loaded before extraction.',
                $documentId,
            );
        }

        try {
            $response = $this->provider->extract($documentId, $document, $docType, $deadline);
        } catch (ExtractionProviderException $exception) {
            return AttachAndExtractResult::failed(
                $exception->errorCode,
                'Document extraction failed.',
                $documentId,
            );
        }

        if (!$response->schemaValid) {
            return AttachAndExtractResult::failed(
                ExtractionErrorCode::SchemaValidationFailure,
                'Extracted document output failed strict schema validation.',
                $documentId,
            );
        }

        return AttachAndExtractResult::succeeded($documentId, $response);
    }

    private static function patientDocumentScopeValid(PatientId $patientId, DocumentId $documentId): bool
    {
        return $patientId->value > 0 && $documentId->value > 0;
    }
}
