<?php

/**
 * Telemetry for the hybrid retrieval merge step.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\AgentForge\Guidelines;

final readonly class RetrievalMergeTelemetry
{
    public function __construct(
        public int $sparseCandidateCount,
        public int $denseCandidateCount,
        public int $overlapCount,
        public int $mergedCandidateCount,
        public int $acceptedCount,
        public float $thresholdApplied,
        public ?float $topPreRerankScore,
        public ?float $topPostRerankScore,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toContext(): array
    {
        return [
            'sparse_candidate_count' => $this->sparseCandidateCount,
            'dense_candidate_count' => $this->denseCandidateCount,
            'overlap_count' => $this->overlapCount,
            'merged_candidate_count' => $this->mergedCandidateCount,
            'accepted_count' => $this->acceptedCount,
            'threshold_applied' => $this->thresholdApplied,
            'top_pre_rerank_score' => $this->topPreRerankScore,
            'top_post_rerank_score' => $this->topPostRerankScore,
        ];
    }
}
