<?php

/**
 * Isolated tests for AgentForge hybrid guideline retrieval.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Tests\Isolated\AgentForge\Guidelines;

use OpenEMR\AgentForge\Guidelines\DeterministicGuidelineEmbeddingProvider;
use OpenEMR\AgentForge\Guidelines\DeterministicReranker;
use OpenEMR\AgentForge\Guidelines\GuidelineCorpusIndexer;
use OpenEMR\AgentForge\Guidelines\GuidelineReranker;
use OpenEMR\AgentForge\Guidelines\HybridGuidelineRetriever;
use OpenEMR\AgentForge\Guidelines\InMemoryGuidelineChunkRepository;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class HybridGuidelineRetrieverTest extends TestCase
{
    public function testSupportedGuidelineQuestionsRetrieveCitedChunks(): void
    {
        $retriever = $this->retriever();

        $result = $retriever->retrieve('What does the guideline say about LDL greater than or equal to 130?');

        $this->assertTrue($result->found());
        $this->assertTrue($result->rerankApplied);
        $this->assertNotSame([], $result->candidates);
        $this->assertSame('guideline', $result->candidates[0]->chunk->citationArray()['source_type']);
        $this->assertStringContainsString('LDL', $result->candidates[0]->chunk->chunkText);
        $this->assertNotNull($result->candidates[0]->rerankScore);
    }

    /**
     * @return array<string, array{string, string}>
     *
     * @codeCoverageIgnore Data providers run before coverage instrumentation starts.
     */
    public static function queryProvider(): array
    {
        return [
            'a1c' => ['How often should A1c be monitored?', 'A1c'],
            'blood pressure' => ['What follow-up context applies to elevated blood pressure?', 'blood pressure'],
            'screening' => ['What preventive screening should primary care review?', 'screening'],
        ];
    }

    #[DataProvider('queryProvider')]
    public function testSparseAndDenseRetrievalCoverPrimaryCareTopics(string $query, string $expectedText): void
    {
        $result = $this->retriever()->retrieve($query);

        $this->assertTrue($result->found());
        $this->assertStringContainsStringIgnoringCase(
            $expectedText,
            $result->candidates[0]->chunk->section . ' ' . $result->candidates[0]->chunk->chunkText,
        );
    }

    public function testOutOfCorpusQuestionsReturnNotFoundAfterRerank(): void
    {
        foreach ([
            'What is the guideline for managing rheumatoid arthritis?',
            'appendicitis guideline',
            'migraine guideline',
        ] as $query) {
            $result = $this->retriever()->retrieve($query);

            $this->assertSame('not_found', $result->status, $query);
            $this->assertSame([], $result->candidates, $query);
            $this->assertTrue($result->rerankApplied, $query);
        }
    }

    public function testRerankerIsAlwaysAppliedToMergedCandidates(): void
    {
        $spy = new SpyReranker();
        $this->retriever($spy)->retrieve('LDL cholesterol 130 follow-up');

        $this->assertSame(1, $spy->callCount);
        $this->assertGreaterThan(0, $spy->lastCandidateCount);
    }

    private function retriever(?GuidelineReranker $reranker = null): HybridGuidelineRetriever
    {
        $repository = new InMemoryGuidelineChunkRepository();
        $embeddingProvider = new DeterministicGuidelineEmbeddingProvider();
        $indexer = new GuidelineCorpusIndexer($repository, $embeddingProvider, $this->corpusDir());
        $indexer->index();

        return new HybridGuidelineRetriever(
            $repository,
            $embeddingProvider,
            $reranker ?? new DeterministicReranker(),
            $indexer->corpusVersion(),
        );
    }

    private function corpusDir(): string
    {
        return dirname(__DIR__, 5) . '/agent-forge/fixtures/clinical-guideline-corpus';
    }
}

final class SpyReranker implements GuidelineReranker
{
    public int $callCount = 0;

    public int $lastCandidateCount = 0;

    public function rerank(string $query, array $candidates): array
    {
        $this->callCount++;
        $this->lastCandidateCount = count($candidates);

        return (new DeterministicReranker())->rerank($query, $candidates);
    }
}
