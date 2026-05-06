<?php

/**
 * Isolated tests for AgentForge guideline SQL repository query shape.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Tests\Isolated\AgentForge\Guidelines;

use OpenEMR\AgentForge\DatabaseExecutor;
use OpenEMR\AgentForge\Guidelines\DeterministicGuidelineEmbeddingProvider;
use OpenEMR\AgentForge\Guidelines\GuidelineChunk;
use OpenEMR\AgentForge\Guidelines\SqlGuidelineChunkRepository;
use PHPUnit\Framework\TestCase;

final class SqlGuidelineChunkRepositoryTest extends TestCase
{
    public function testReplaceCorpusWritesOnlyGuidelineTablesAndVectorEmbeddings(): void
    {
        $executor = new GuidelineRepositoryExecutor();
        $repository = new SqlGuidelineChunkRepository($executor);

        $repository->replaceCorpus('clinical-guideline-demo-2026-05-06', [
            new GuidelineChunk(
                'ldl-01',
                'clinical-guideline-demo-2026-05-06',
                'LDL Source',
                'ldl.md',
                'LDL Follow-Up',
                'LDL greater than or equal to 130 requires review.',
                [],
            ),
        ], new DeterministicGuidelineEmbeddingProvider());

        $sql = implode("\n", array_column($executor->statements, 'sql'));
        $this->assertStringContainsString('clinical_guideline_chunks', $sql);
        $this->assertStringContainsString('clinical_guideline_chunk_embeddings', $sql);
        $this->assertStringContainsString('VEC_FromText', $sql);
        $this->assertStringContainsString('corpus_version', $sql);
        $this->assertStringNotContainsString('clinical_document_fact_embeddings', $sql);
    }

    public function testDenseSearchUsesGuidelineEmbeddingTableOnly(): void
    {
        $executor = new GuidelineRepositoryExecutor([
            [
                'chunk_id' => 'ldl-01',
                'corpus_version' => 'clinical-guideline-demo-2026-05-06',
                'source_title' => 'LDL Source',
                'source_url_or_file' => 'ldl.md',
                'section' => 'LDL Follow-Up',
                'chunk_text' => 'LDL greater than or equal to 130 requires review.',
                'citation_json' => '{}',
                'dense_score' => 0.75,
            ],
        ]);

        $result = (new SqlGuidelineChunkRepository($executor))->denseSearch(
            'clinical-guideline-demo-2026-05-06',
            array_fill(0, DeterministicGuidelineEmbeddingProvider::DIMENSIONS, 0.0),
            5,
        );

        $this->assertCount(1, $result);
        $sql = $executor->queries[0]['sql'];
        $this->assertStringContainsString('clinical_guideline_chunk_embeddings', $sql);
        $this->assertStringContainsString('VEC_DISTANCE_COSINE', $sql);
        $this->assertStringContainsString('c.corpus_version = e.corpus_version', $sql);
        $this->assertStringNotContainsString('clinical_document_fact_embeddings', $sql);
        $this->assertStringNotContainsString('patient', strtolower($sql));
    }
}

final class GuidelineRepositoryExecutor implements DatabaseExecutor
{
    /** @var list<array{sql: string, binds: list<mixed>}> */
    public array $queries = [];

    /** @var list<array{sql: string, binds: list<mixed>}> */
    public array $statements = [];

    /** @param list<array<string, mixed>> $records */
    public function __construct(private readonly array $records = [])
    {
    }

    public function fetchRecords(string $sql, array $binds = []): array
    {
        $this->queries[] = ['sql' => $sql, 'binds' => $binds];

        return $this->records;
    }

    public function executeStatement(string $sql, array $binds = []): void
    {
        $this->statements[] = ['sql' => $sql, 'binds' => $binds];
    }

    public function executeAffected(string $sql, array $binds = []): int
    {
        $this->statements[] = ['sql' => $sql, 'binds' => $binds];

        return 1;
    }

    public function insert(string $sql, array $binds = []): int
    {
        $this->statements[] = ['sql' => $sql, 'binds' => $binds];

        return 1;
    }
}
