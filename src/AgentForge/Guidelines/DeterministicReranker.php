<?php

/**
 * Deterministic token-overlap reranker for CI and fixture evals.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\AgentForge\Guidelines;

final class DeterministicReranker implements GuidelineReranker
{
    public function name(): string
    {
        return 'deterministic';
    }

    public function rerank(string $query, array $candidates): array
    {
        $queryTokens = DeterministicGuidelineEmbeddingProvider::tokens($query);
        $ranked = [];
        foreach ($candidates as $candidate) {
            $chunkTokens = DeterministicGuidelineEmbeddingProvider::tokens(
                $candidate->chunk->section . ' ' . $candidate->chunk->chunkText,
            );
            $overlap = count(array_intersect($queryTokens, $chunkTokens));
            $denominator = max(1, min(count($queryTokens), count($chunkTokens)));
            $score = ($overlap / $denominator) + ($candidate->sparseScore * 0.2) + ($candidate->denseScore * 0.2);
            $ranked[] = $candidate->withRerankScore(round($score, 6));
        }

        usort(
            $ranked,
            static fn (GuidelineSearchCandidate $a, GuidelineSearchCandidate $b): int => $b->score() <=> $a->score(),
        );

        return $ranked;
    }
}
