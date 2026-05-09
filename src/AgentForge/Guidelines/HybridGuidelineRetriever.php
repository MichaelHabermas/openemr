<?php

/**
 * Sparse+dense guideline retrieval with mandatory rerank and thresholding.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\AgentForge\Guidelines;

final readonly class HybridGuidelineRetriever implements GuidelineRetriever
{
    public function __construct(
        private GuidelineChunkRepository $repository,
        private GuidelineEmbeddingProvider $embeddingProvider,
        private GuidelineReranker $reranker,
        private string $corpusVersion,
        private float $threshold = 0.4,
        private int $topK = 3,
        private int $candidateLimit = 8,
    ) {
    }

    public function retrieve(string $query): GuidelineRetrievalResult
    {
        $query = trim($query);
        if ($query === '') {
            return new GuidelineRetrievalResult('not_found', [], null, $this->threshold);
        }

        $sparse = $this->repository->sparseSearch($this->corpusVersion, $query, $this->candidateLimit);
        $dense = $this->repository->denseSearch(
            $this->corpusVersion,
            $this->embeddingProvider->embed($query),
            $this->candidateLimit,
        );
        $merged = $this->mergeCandidates($sparse, $dense);
        $reranked = $this->reranker->rerank($query, $merged);
        $accepted = array_values(array_filter(
            $reranked,
            fn (GuidelineSearchCandidate $candidate): bool => $candidate->score() >= $this->threshold,
        ));
        $accepted = array_slice($accepted, 0, $this->topK);

        return new GuidelineRetrievalResult(
            $accepted === [] ? 'not_found' : 'found',
            $accepted,
            $this->reranker->name(),
            $this->threshold,
        );
    }

    /**
     * @param list<GuidelineSearchCandidate> $sparse
     * @param list<GuidelineSearchCandidate> $dense
     * @return list<GuidelineSearchCandidate>
     */
    private function mergeCandidates(array $sparse, array $dense): array
    {
        $byChunkId = [];
        foreach (array_merge($sparse, $dense) as $candidate) {
            $existing = $byChunkId[$candidate->chunk->chunkId] ?? null;
            if (!$existing instanceof GuidelineSearchCandidate) {
                $byChunkId[$candidate->chunk->chunkId] = $candidate;
                continue;
            }

            $byChunkId[$candidate->chunk->chunkId] = $existing->withMergedScores(
                max($existing->sparseScore, $candidate->sparseScore),
                max($existing->denseScore, $candidate->denseScore),
            );
        }

        $merged = array_values($byChunkId);
        usort(
            $merged,
            static fn (GuidelineSearchCandidate $a, GuidelineSearchCandidate $b): int => $b->score() <=> $a->score(),
        );

        return $merged;
    }
}
