<?php

/**
 * In-memory guideline repository for deterministic evals and isolated tests.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\AgentForge\Guidelines;

final class InMemoryGuidelineChunkRepository implements GuidelineChunkRepository
{
    /** @var array<string, list<GuidelineChunk>> */
    private array $chunksByVersion = [];

    /** @var array<string, list<float>> */
    private array $embeddingsByChunkId = [];

    public int $replaceCount = 0;
    public int $denseSearchCount = 0;

    public function replaceCorpus(string $corpusVersion, array $chunks, GuidelineEmbeddingProvider $embeddingProvider): void
    {
        $this->replaceCount++;
        $this->chunksByVersion[$corpusVersion] = $chunks;
        foreach ($chunks as $chunk) {
            $this->embeddingsByChunkId[$chunk->chunkId] = $embeddingProvider->embed(
                $chunk->section . ' ' . $chunk->chunkText,
            );
        }
    }

    public function sparseSearch(string $corpusVersion, string $query, int $limit): array
    {
        $queryTokens = DeterministicGuidelineEmbeddingProvider::tokens($query);
        $candidates = [];
        foreach ($this->findActiveByVersion($corpusVersion) as $chunk) {
            $chunkTokens = DeterministicGuidelineEmbeddingProvider::tokens($chunk->section . ' ' . $chunk->chunkText);
            $overlap = count(array_intersect($queryTokens, $chunkTokens));
            if ($overlap > 0) {
                $candidates[] = new GuidelineSearchCandidate(
                    $chunk,
                    round($overlap / max(1, count($queryTokens)), 6),
                );
            }
        }

        usort(
            $candidates,
            static fn (GuidelineSearchCandidate $a, GuidelineSearchCandidate $b): int => $b->sparseScore <=> $a->sparseScore,
        );

        return array_slice($candidates, 0, $limit);
    }

    public function denseSearch(string $corpusVersion, array $queryEmbedding, int $limit): array
    {
        $this->denseSearchCount++;
        $candidates = [];
        foreach ($this->findActiveByVersion($corpusVersion) as $chunk) {
            $embedding = $this->embeddingsByChunkId[$chunk->chunkId] ?? [];
            $score = self::cosineSimilarity($queryEmbedding, $embedding);
            if ($score > 0.0) {
                $candidates[] = new GuidelineSearchCandidate($chunk, denseScore: round($score, 6));
            }
        }

        usort(
            $candidates,
            static fn (GuidelineSearchCandidate $a, GuidelineSearchCandidate $b): int => $b->denseScore <=> $a->denseScore,
        );

        return array_slice($candidates, 0, $limit);
    }

    public function findActiveByVersion(string $corpusVersion): array
    {
        return $this->chunksByVersion[$corpusVersion] ?? [];
    }

    /**
     * @param list<float> $a
     * @param list<float> $b
     */
    private static function cosineSimilarity(array $a, array $b): float
    {
        $dot = 0.0;
        $aMag = 0.0;
        $bMag = 0.0;
        $count = min(count($a), count($b));
        for ($i = 0; $i < $count; $i++) {
            $aValue = $a[$i];
            $bValue = $b[$i];
            $dot += $aValue * $bValue;
            $aMag += $aValue * $aValue;
            $bMag += $bValue * $bValue;
        }

        if ($aMag <= 0.0 || $bMag <= 0.0) {
            return 0.0;
        }

        return $dot / (sqrt($aMag) * sqrt($bMag));
    }
}
