<?php

/**
 * Isolated tests for the document retraction hook wrapper.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Tests\Isolated\AgentForge\Document;

require_once __DIR__ . '/DocumentUploadEnqueuerTest.php';

use OpenEMR\AgentForge\Auth\PatientId;
use OpenEMR\AgentForge\Document\DocumentId;
use OpenEMR\AgentForge\Document\DocumentJob;
use OpenEMR\AgentForge\Document\DocumentJobId;
use OpenEMR\AgentForge\Document\DocumentJobRepository;
use OpenEMR\AgentForge\Document\DocumentRetractionHook;
use OpenEMR\AgentForge\Document\DocumentRetractionReason;
use OpenEMR\AgentForge\Document\DocumentType;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class DocumentRetractionHookTest extends TestCase
{
    public function testNonNumericDocumentIdReturnsWithoutResolvingRepository(): void
    {
        $called = false;

        DocumentRetractionHook::dispatch('not-an-id', function () use (&$called): DocumentJobRepository {
            $called = true;
            throw new RuntimeException('should not resolve');
        });

        $this->assertFalse($called);
    }

    public function testInvalidDocumentIdIsCaught(): void
    {
        $called = false;

        DocumentRetractionHook::dispatch(0, function () use (&$called): DocumentJobRepository {
            $called = true;
            throw new RuntimeException('should not resolve');
        });

        $this->assertFalse($called);
    }

    public function testValidDocumentIdRetractsByDocument(): void
    {
        $repository = new RetractionRecordingRepository();

        DocumentRetractionHook::dispatch(123, static fn (): DocumentJobRepository => $repository);

        $this->assertSame(123, $repository->documentId?->value);
        $this->assertSame(DocumentRetractionReason::SourceDocumentDeleted, $repository->reason);
    }

    public function testRepositoryExceptionIsCaught(): void
    {
        $this->expectNotToPerformAssertions();

        DocumentRetractionHook::dispatch(123, static function (): DocumentJobRepository {
            return new ThrowingDocumentJobRepository();
        });
    }
}

final class RetractionRecordingRepository implements DocumentJobRepository
{
    public ?DocumentId $documentId = null;
    public ?DocumentRetractionReason $reason = null;

    public function enqueue(PatientId $patientId, DocumentId $documentId, DocumentType $docType): DocumentJobId
    {
        return new DocumentJobId(1);
    }

    public function findById(DocumentJobId $id): ?DocumentJob
    {
        return null;
    }

    public function findOneByUniqueKey(PatientId $patientId, DocumentId $documentId, DocumentType $docType): ?DocumentJob
    {
        return null;
    }

    public function retractByDocument(DocumentId $documentId, DocumentRetractionReason $reason): int
    {
        $this->documentId = $documentId;
        $this->reason = $reason;

        return 2;
    }
}
