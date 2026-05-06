<?php

/**
 * Isolated tests for AgentForge guideline corpus indexing.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Tests\Isolated\AgentForge\Guidelines;

use OpenEMR\AgentForge\Guidelines\DeterministicGuidelineEmbeddingProvider;
use OpenEMR\AgentForge\Guidelines\GuidelineCorpusIndexer;
use OpenEMR\AgentForge\Guidelines\InMemoryGuidelineChunkRepository;
use PHPUnit\Framework\TestCase;

final class GuidelineCorpusIndexerTest extends TestCase
{
    public function testCorpusChunksAreDeterministicAndVersioned(): void
    {
        $repository = new InMemoryGuidelineChunkRepository();
        $indexer = new GuidelineCorpusIndexer(
            $repository,
            new DeterministicGuidelineEmbeddingProvider(),
            $this->corpusDir(),
        );

        $first = $indexer->loadChunks();
        $second = $indexer->loadChunks();

        $this->assertCount(25, $first);
        $this->assertSame($this->chunkIds($first), $this->chunkIds($second));
        $this->assertSame('clinical-guideline-demo-2026-05-06', $indexer->corpusVersion());
        $this->assertStringStartsWith('acc-aha-ldl-follow-up-', $first[0]->chunkId);
    }

    public function testIndexingIsIdempotentForSameCorpusVersion(): void
    {
        $repository = new InMemoryGuidelineChunkRepository();
        $indexer = new GuidelineCorpusIndexer(
            $repository,
            new DeterministicGuidelineEmbeddingProvider(),
            $this->corpusDir(),
        );

        $firstCount = $indexer->index();
        $firstIds = $this->chunkIds($repository->findActiveByVersion($indexer->corpusVersion()));
        $secondCount = $indexer->index();
        $secondIds = $this->chunkIds($repository->findActiveByVersion($indexer->corpusVersion()));

        $this->assertSame(25, $firstCount);
        $this->assertSame($firstCount, $secondCount);
        $this->assertSame($firstIds, $secondIds);
        $this->assertSame(2, $repository->replaceCount);
    }

    private function corpusDir(): string
    {
        return dirname(__DIR__, 5) . '/agent-forge/fixtures/clinical-guideline-corpus';
    }

    /**
     * @param list<\OpenEMR\AgentForge\Guidelines\GuidelineChunk> $chunks
     * @return list<string>
     */
    private function chunkIds(array $chunks): array
    {
        return array_map(
            static fn (\OpenEMR\AgentForge\Guidelines\GuidelineChunk $chunk): string => $chunk->chunkId,
            $chunks,
        );
    }
}
