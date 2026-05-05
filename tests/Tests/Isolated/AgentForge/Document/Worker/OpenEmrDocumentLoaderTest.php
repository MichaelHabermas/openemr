<?php

/**
 * Isolated tests for the OpenEMR legacy document loader adapter.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Tests\Isolated\AgentForge\Document\Worker;

use BadMethodCallException;
use OpenEMR\AgentForge\Document\DocumentId;
use OpenEMR\AgentForge\Document\Worker\DocumentLoadException;
use OpenEMR\AgentForge\Document\Worker\OpenEmrDocumentLoader;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class OpenEmrDocumentLoaderTest extends TestCase
{
    public function testLoadsLegacyDocumentPayload(): void
    {
        $loader = new OpenEmrDocumentLoader(
            static fn(int $documentId): object => new LegacyDocumentStub(123, 'abc', 'application/pdf', 'lab.pdf'),
        );

        $result = $loader->load(new DocumentId(123));

        $this->assertSame('abc', $result->bytes);
        $this->assertSame('application/pdf', $result->mimeType);
        $this->assertSame('lab.pdf', $result->name);
    }

    public function testEmptyLegacyDocumentMapsToMissing(): void
    {
        $loader = new OpenEmrDocumentLoader(
            static fn(int $documentId): object => new LegacyDocumentStub(null, false, 'application/pdf', 'lab.pdf'),
        );

        try {
            $loader->load(new DocumentId(123));
            $this->fail('Expected document load exception.');
        } catch (DocumentLoadException $e) {
            $this->assertSame('source_document_missing', $e->errorCode);
        }
    }

    public function testDeletedLegacyDocumentMapsToDeleted(): void
    {
        $loader = new OpenEmrDocumentLoader(
            static fn(int $documentId): object => new LegacyDocumentStub(123, 'abc', 'text/plain', 'note.txt', true),
        );

        try {
            $loader->load(new DocumentId(123));
            $this->fail('Expected document load exception.');
        } catch (DocumentLoadException $e) {
            $this->assertSame('source_document_deleted', $e->errorCode);
        }
    }

    public function testExpiredLegacyDocumentMapsToExpired(): void
    {
        $loader = new OpenEmrDocumentLoader(
            static fn(int $documentId): object => new ThrowingLegacyDocumentStub(
                new BadMethodCallException('Should not attempt to retrieve data from expired documents'),
                expired: true,
            ),
        );

        try {
            $loader->load(new DocumentId(123));
            $this->fail('Expected document load exception.');
        } catch (DocumentLoadException $e) {
            $this->assertSame('source_document_expired', $e->errorCode);
        }
    }

    public function testUnexpectedLegacyFailureMapsToUnreadable(): void
    {
        $loader = new OpenEmrDocumentLoader(
            static fn(int $documentId): object => new ThrowingLegacyDocumentStub(new RuntimeException('storage down')),
        );

        try {
            $loader->load(new DocumentId(123));
            $this->fail('Expected document load exception.');
        } catch (DocumentLoadException $e) {
            $this->assertSame('source_document_unreadable', $e->errorCode);
        }
    }

    public function testMetadataFailureMapsToUnreadable(): void
    {
        $loader = new OpenEmrDocumentLoader(
            static fn(int $documentId): object => new BadMetadataLegacyDocumentStub(),
        );

        try {
            $loader->load(new DocumentId(123));
            $this->fail('Expected document load exception.');
        } catch (DocumentLoadException $e) {
            $this->assertSame('source_document_unreadable', $e->errorCode);
        }
    }
}

final readonly class LegacyDocumentStub
{
    public function __construct(
        private ?int $id,
        private false|string $bytes,
        private string $mimeType,
        private string $name,
        private bool $deleted = false,
    ) {
    }

    public function get_id(): ?int
    {
        return $this->id;
    }

    public function is_deleted(): bool
    {
        return $this->deleted;
    }

    public function has_expired(): bool
    {
        return false;
    }

    public function get_data(): false|string
    {
        return $this->bytes;
    }

    public function get_mimetype(): string
    {
        return $this->mimeType;
    }

    public function get_name(): string
    {
        return $this->name;
    }
}

final readonly class ThrowingLegacyDocumentStub
{
    public function __construct(private \Throwable $throwable, private bool $expired = false)
    {
    }

    public function get_id(): int
    {
        return 123;
    }

    public function is_deleted(): bool
    {
        return false;
    }

    public function has_expired(): bool
    {
        return $this->expired;
    }

    public function get_data(): string
    {
        throw $this->throwable;
    }
}

final readonly class BadMetadataLegacyDocumentStub
{
    public function get_id(): int
    {
        return 123;
    }

    public function is_deleted(): bool
    {
        return false;
    }

    public function has_expired(): bool
    {
        return false;
    }

    public function get_data(): string
    {
        return 'abc';
    }

    public function get_mimetype(): string
    {
        throw new RuntimeException('metadata unavailable');
    }

    public function get_name(): string
    {
        return 'lab.pdf';
    }
}
