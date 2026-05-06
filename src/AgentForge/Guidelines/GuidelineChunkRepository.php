<?php

/**
 * Storage boundary for guideline chunks and embeddings.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\AgentForge\Guidelines;

interface GuidelineChunkRepository
{
    /**
     * @param list<GuidelineChunk> $chunks
     */
    public function replaceCorpus(string $corpusVersion, array $chunks, GuidelineEmbeddingProvider $embeddingProvider): void;

    /**
     * @return list<GuidelineSearchCandidate>
     */
    public function sparseSearch(string $corpusVersion, string $query, int $limit): array;

    /**
     * @param list<float> $queryEmbedding
     * @return list<GuidelineSearchCandidate>
     */
    public function denseSearch(string $corpusVersion, array $queryEmbedding, int $limit): array;

    /**
     * @return list<GuidelineChunk>
     */
    public function findActiveByVersion(string $corpusVersion): array;
}
