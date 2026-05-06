<?php

/**
 * Isolated tests for in-memory source document storage.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Tests\Isolated\AgentForge\Document;

use OpenEMR\AgentForge\Auth\PatientId;
use OpenEMR\AgentForge\Document\DocumentId;
use OpenEMR\AgentForge\Document\DocumentType;
use OpenEMR\AgentForge\Document\InMemorySourceDocumentStorage;
use OpenEMR\AgentForge\Document\Worker\DocumentLoadException;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class InMemorySourceDocumentStorageTest extends TestCase
{
    public function testStoresSequentialIdsAndLoadsPayload(): void
    {
        $storage = new InMemorySourceDocumentStorage(100);
        $file = tempnam(sys_get_temp_dir(), 'agentforge-document-');
        $this->assertIsString($file);
        file_put_contents($file, 'pdf-bytes');

        $documentId = $storage->storeUploadedFile(new PatientId(1), $file, DocumentType::LabPdf);
        $loaded = $storage->load($documentId);

        $this->assertSame(100, $documentId->value);
        $this->assertSame('pdf-bytes', $loaded->bytes);
        $this->assertSame(basename($file), $loaded->name);

        unlink($file);
    }

    public function testMissingFileFailsStorage(): void
    {
        $this->expectException(RuntimeException::class);

        (new InMemorySourceDocumentStorage())->storeUploadedFile(
            new PatientId(1),
            sys_get_temp_dir() . '/missing-agentforge-document.pdf',
            DocumentType::LabPdf,
        );
    }

    public function testMissingDocumentIdFailsLoad(): void
    {
        $this->expectException(DocumentLoadException::class);

        (new InMemorySourceDocumentStorage())->load(new DocumentId(123));
    }
}
