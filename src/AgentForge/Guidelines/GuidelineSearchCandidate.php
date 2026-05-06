<?php

/**
 * Candidate guideline chunk returned by retrieval and rerank.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\AgentForge\Guidelines;

final readonly class GuidelineSearchCandidate
{
    public function __construct(
        public GuidelineChunk $chunk,
        public float $sparseScore = 0.0,
        public float $denseScore = 0.0,
        public ?float $rerankScore = null,
    ) {
    }

    public function score(): float
    {
        return $this->rerankScore ?? max($this->sparseScore, $this->denseScore);
    }

    public function withMergedScores(float $sparseScore, float $denseScore): self
    {
        return new self($this->chunk, $sparseScore, $denseScore, $this->rerankScore);
    }

    public function withRerankScore(float $rerankScore): self
    {
        return new self($this->chunk, $this->sparseScore, $this->denseScore, $rerankScore);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'chunk_id' => $this->chunk->chunkId,
            'source_title' => $this->chunk->sourceTitle,
            'source_url_or_file' => $this->chunk->sourceUrlOrFile,
            'section' => $this->chunk->section,
            'evidence_text' => $this->chunk->chunkText,
            'sparse_score' => $this->sparseScore,
            'dense_score' => $this->denseScore,
            'rerank_score' => $this->rerankScore,
            'score' => $this->score(),
            'citation' => $this->chunk->citationArray(),
        ];
    }
}
