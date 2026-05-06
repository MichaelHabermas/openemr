<?php

/**
 * OpenEMR-backed storage seam for direct attach/extract calls.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\AgentForge\Document;

use Closure;
use OpenEMR\AgentForge\Auth\PatientId;
use OpenEMR\AgentForge\DatabaseExecutor;
use RuntimeException;
use Throwable;

final readonly class OpenEmrSourceDocumentStorage implements SourceDocumentStorage
{
    /** @var Closure(DocumentType): CategoryId */
    private Closure $categoryResolver;

    /** @var Closure(): object */
    private Closure $documentFactory;

    /**
     * @param null|Closure(DocumentType): CategoryId $categoryResolver
     * @param null|Closure(): object $documentFactory
     */
    public function __construct(
        DatabaseExecutor $executor,
        ?Closure $categoryResolver = null,
        ?Closure $documentFactory = null,
    ) {
        $this->categoryResolver = $categoryResolver ?? self::defaultCategoryResolver($executor);
        $this->documentFactory = $documentFactory ?? static function (): object {
            if (!class_exists(\Document::class)) {
                require_once dirname(__DIR__, 3) . '/library/classes/Document.class.php';
            }

            return new \Document();
        };
    }

    public function storeUploadedFile(PatientId $patientId, string $filePath, DocumentType $docType): DocumentId
    {
        $bytes = SourceUploadFile::readBytesOrThrow($filePath);

        $mimeType = mime_content_type($filePath);
        if (!is_string($mimeType) || $mimeType === '') {
            $mimeType = 'application/octet-stream';
        }

        try {
            $document = ($this->documentFactory)();
            $categoryId = ($this->categoryResolver)($docType);
            $error = $this->createDocument(
                $document,
                $patientId->value,
                $categoryId->value,
                basename($filePath),
                $mimeType,
                $bytes,
                $filePath,
            );
        } catch (Throwable $e) {
            throw new RuntimeException('OpenEMR source document storage failed.', 0, $e);
        }

        if (!is_string($error) || $error !== '') {
            throw new RuntimeException('OpenEMR source document storage failed.');
        }

        $documentId = $this->getDocumentId($document);
        if (!is_scalar($documentId) || (int) $documentId <= 0) {
            throw new RuntimeException('OpenEMR source document id is invalid.');
        }

        return new DocumentId((int) $documentId);
    }

    private function createDocument(
        object $document,
        int $patientId,
        int $categoryId,
        string $filename,
        string $mimeType,
        string &$bytes,
        string $tmpFile,
    ): mixed {
        if (!method_exists($document, 'createDocument')) {
            throw new RuntimeException('OpenEMR source document writer is unavailable.');
        }

        return $document->{'createDocument'}($patientId, $categoryId, $filename, $mimeType, $bytes, '', 1, 0, $tmpFile);
    }

    private function getDocumentId(object $document): mixed
    {
        if (!method_exists($document, 'get_id')) {
            throw new RuntimeException('OpenEMR source document id is unavailable.');
        }

        return $document->{'get_id'}();
    }

    /** @return Closure(DocumentType): CategoryId */
    private static function defaultCategoryResolver(DatabaseExecutor $executor): Closure
    {
        return static function (DocumentType $docType) use ($executor): CategoryId {
            $records = $executor->fetchRecords(
                'SELECT category_id FROM clinical_document_type_mappings '
                . 'WHERE doc_type = ? AND active = 1 ORDER BY id ASC LIMIT 1',
                [$docType->value],
            );

            if ($records === [] || !isset($records[0]['category_id']) || !is_scalar($records[0]['category_id'])) {
                throw new RuntimeException('No active clinical document category mapping exists for document type.');
            }

            return new CategoryId((int) $records[0]['category_id']);
        };
    }
}
