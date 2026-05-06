<?php

/**
 * Isolated tests for document fact vector persistence.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Tests\Isolated\AgentForge\Document\Embedding;

use DomainException;
use OpenEMR\AgentForge\Document\Embedding\EmbeddingProvider;
use OpenEMR\AgentForge\Document\Embedding\SqlDocumentFactEmbeddingRepository;
use OpenEMR\Tests\Isolated\AgentForge\Support\FakeDatabaseExecutor;
use PHPUnit\Framework\TestCase;

final class SqlDocumentFactEmbeddingRepositoryTest extends TestCase
{
    public function testUpsertRejectsWrongVectorDimensionsBeforeSqlWrite(): void
    {
        $executor = new FakeDatabaseExecutor();

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('exactly 1536 dimensions');

        (new SqlDocumentFactEmbeddingRepository($executor))->upsert(41, 'LDL Cholesterol 148 mg/dL', new WrongDimensionEmbeddingProvider());
    }

    public function testUpsertWritesDocumentFactEmbeddingOnlyToDocumentTable(): void
    {
        $executor = new FakeDatabaseExecutor();

        (new SqlDocumentFactEmbeddingRepository($executor))->upsert(41, 'LDL Cholesterol 148 mg/dL', new FixedEmbeddingProvider());

        $this->assertCount(1, $executor->statements);
        $this->assertStringContainsString('INSERT INTO clinical_document_fact_embeddings', $executor->statements[0]['sql']);
        $this->assertStringNotContainsString('clinical_guideline_chunk_embeddings', $executor->statements[0]['sql']);
        $this->assertSame(41, $executor->statements[0]['binds'][0]);
        $this->assertSame('fixed-document-embedding', $executor->statements[0]['binds'][2]);
    }

    public function testDeactivateByDocumentMarksAllDocumentFactEmbeddingsInactive(): void
    {
        $executor = new FakeDatabaseExecutor();

        (new SqlDocumentFactEmbeddingRepository($executor))->deactivateByDocument(44);

        $this->assertStringContainsString('UPDATE clinical_document_fact_embeddings e', $executor->statements[0]['sql']);
        $this->assertStringContainsString('INNER JOIN clinical_document_facts f ON f.id = e.fact_id', $executor->statements[0]['sql']);
        $this->assertStringContainsString('SET e.active = 0', $executor->statements[0]['sql']);
        $this->assertSame([44], $executor->statements[0]['binds']);
    }
}

final class FixedEmbeddingProvider implements EmbeddingProvider
{
    public function model(): string
    {
        return 'fixed-document-embedding';
    }

    public function embed(string $text): array
    {
        return array_fill(0, 1536, 0.1);
    }
}

final class WrongDimensionEmbeddingProvider implements EmbeddingProvider
{
    public function model(): string
    {
        return 'wrong-dimension';
    }

    public function embed(string $text): array
    {
        return [0.1, 0.2];
    }
}
