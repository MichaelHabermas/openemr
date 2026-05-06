<?php

/**
 * Isolated tests for OpenEMR-backed source document storage.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Tests\Isolated\AgentForge\Document;

use OpenEMR\AgentForge\Auth\PatientId;
use OpenEMR\AgentForge\Document\CategoryId;
use OpenEMR\AgentForge\Document\DocumentType;
use OpenEMR\AgentForge\Document\OpenEmrSourceDocumentStorage;
use OpenEMR\Tests\Isolated\AgentForge\Support\FakeDatabaseExecutor;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class OpenEmrSourceDocumentStorageTest extends TestCase
{
    public function testStoresUploadedFileThroughLegacyDocumentWriter(): void
    {
        $legacy = new LegacyDocumentWriterStub(456);
        $storage = new OpenEmrSourceDocumentStorage(
            new FakeDatabaseExecutor(),
            static fn(DocumentType $docType): CategoryId => new CategoryId(7),
            static fn(): object => $legacy,
        );
        $file = tempnam(sys_get_temp_dir(), 'agentforge-openemr-document-');
        $this->assertIsString($file);
        file_put_contents($file, 'pdf-bytes');

        $documentId = $storage->storeUploadedFile(new PatientId(99), $file, DocumentType::LabPdf);

        $this->assertSame(456, $documentId->value);
        $this->assertSame(99, $legacy->patientId);
        $this->assertSame(7, $legacy->categoryId);
        $this->assertSame('pdf-bytes', $legacy->bytes);

        unlink($file);
    }

    public function testLegacyStorageErrorFailsStorage(): void
    {
        $storage = new OpenEmrSourceDocumentStorage(
            new FakeDatabaseExecutor(),
            static fn(DocumentType $docType): CategoryId => new CategoryId(7),
            static fn(): object => new LegacyDocumentWriterStub(0, 'failed'),
        );
        $file = tempnam(sys_get_temp_dir(), 'agentforge-openemr-document-');
        $this->assertIsString($file);
        file_put_contents($file, 'pdf-bytes');

        try {
            $storage->storeUploadedFile(new PatientId(99), $file, DocumentType::LabPdf);
            $this->fail('Expected storage failure.');
        } catch (RuntimeException $e) {
            $this->assertSame('OpenEMR source document storage failed.', $e->getMessage());
        } finally {
            unlink($file);
        }
    }
}

final class LegacyDocumentWriterStub
{
    public ?int $patientId = null;
    public ?int $categoryId = null;
    public ?string $bytes = null;

    public function __construct(private readonly int $id, private readonly string $error = '')
    {
    }

    public function createDocument(
        int $patientId,
        int $categoryId,
        string $filename,
        string $mimetype,
        string &$data,
    ): string {
        $this->patientId = $patientId;
        $this->categoryId = $categoryId;
        $this->bytes = $data;

        return $this->error;
    }

    public function get_id(): int
    {
        return $this->id;
    }
}
