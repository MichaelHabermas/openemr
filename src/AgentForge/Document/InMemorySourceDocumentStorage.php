<?php

/**
 * Test/eval source-document storage.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\AgentForge\Document;

use OpenEMR\AgentForge\Auth\PatientId;
use OpenEMR\AgentForge\Document\Worker\DocumentLoader;
use OpenEMR\AgentForge\Document\Worker\DocumentLoadException;
use OpenEMR\AgentForge\Document\Worker\DocumentLoadResult;
use RuntimeException;

final class InMemorySourceDocumentStorage implements SourceDocumentStorage, DocumentLoader
{
    private int $nextDocumentId;

    /** @var array<int, DocumentLoadResult> */
    private array $documents = [];

    public function __construct(int $firstDocumentId = 1)
    {
        $this->nextDocumentId = $firstDocumentId;
    }

    public function storeUploadedFile(PatientId $patientId, string $filePath, DocumentType $docType): DocumentId
    {
        $bytes = SourceUploadFile::readBytesOrThrow($filePath);

        $documentId = new DocumentId($this->nextDocumentId++);
        $this->documents[$documentId->value] = new DocumentLoadResult(
            $bytes,
            $this->mimeTypeFor($filePath),
            basename($filePath),
        );

        return $documentId;
    }

    public function load(DocumentId $documentId): DocumentLoadResult
    {
        return $this->documents[$documentId->value] ?? throw DocumentLoadException::missing();
    }

    public function put(DocumentId $documentId, DocumentLoadResult $document): void
    {
        $this->documents[$documentId->value] = $document;
    }

    private function mimeTypeFor(string $filePath): string
    {
        $extension = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));

        return match ($extension) {
            'pdf' => 'application/pdf',
            'png' => 'image/png',
            'jpg', 'jpeg' => 'image/jpeg',
            'webp' => 'image/webp',
            default => 'application/octet-stream',
        };
    }
}
