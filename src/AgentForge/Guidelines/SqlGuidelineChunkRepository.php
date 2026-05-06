<?php

/**
 * SQL-backed guideline corpus repository.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\AgentForge\Guidelines;

use DomainException;
use OpenEMR\AgentForge\DatabaseExecutor;
use OpenEMR\AgentForge\StringKeyedArray;

final readonly class SqlGuidelineChunkRepository implements GuidelineChunkRepository
{
    public function __construct(private DatabaseExecutor $db)
    {
    }

    public function replaceCorpus(string $corpusVersion, array $chunks, GuidelineEmbeddingProvider $embeddingProvider): void
    {
        if ($chunks === []) {
            throw new DomainException('Guideline corpus rebuild requires at least one chunk.');
        }
        $chunkIds = array_map(static fn (GuidelineChunk $chunk): string => $chunk->chunkId, $chunks);
        $placeholders = implode(', ', array_fill(0, count($chunkIds), '?'));

        foreach ($chunks as $chunk) {
            $citationJson = json_encode($chunk->citationArray(), JSON_THROW_ON_ERROR);
            $this->db->executeStatement(
                'INSERT INTO clinical_guideline_chunks '
                . '(chunk_id, corpus_version, source_title, source_url_or_file, section, chunk_text, citation_json, active, created_at) '
                . 'VALUES (?, ?, ?, ?, ?, ?, ?, 0, NOW()) '
                . 'ON DUPLICATE KEY UPDATE source_title = VALUES(source_title), source_url_or_file = VALUES(source_url_or_file), '
                . 'section = VALUES(section), chunk_text = VALUES(chunk_text), citation_json = VALUES(citation_json)',
                [
                    $chunk->chunkId,
                    $chunk->corpusVersion,
                    $chunk->sourceTitle,
                    $chunk->sourceUrlOrFile,
                    $chunk->section,
                    $chunk->chunkText,
                    $citationJson,
                ],
            );
            $embedding = self::vectorLiteral($embeddingProvider->embed($chunk->section . ' ' . $chunk->chunkText));
            $this->db->executeStatement(
                'INSERT INTO clinical_guideline_chunk_embeddings '
                . '(chunk_id, corpus_version, embedding, embedding_model, active, created_at) '
                . 'VALUES (?, ?, VEC_FromText(?), ?, 0, NOW()) '
                . 'ON DUPLICATE KEY UPDATE embedding = VALUES(embedding), embedding_model = VALUES(embedding_model)',
                [$chunk->chunkId, $chunk->corpusVersion, $embedding, $embeddingProvider->modelName()],
            );
        }

        $this->db->executeStatement(
            'UPDATE clinical_guideline_chunk_embeddings SET active = 0 WHERE corpus_version = ? '
            . 'AND chunk_id NOT IN (' . $placeholders . ')',
            array_merge([$corpusVersion], $chunkIds),
        );
        $this->db->executeStatement(
            'UPDATE clinical_guideline_chunks SET active = 0 WHERE corpus_version = ? '
            . 'AND chunk_id NOT IN (' . $placeholders . ')',
            array_merge([$corpusVersion], $chunkIds),
        );
        $this->db->executeStatement(
            'UPDATE clinical_guideline_chunks SET active = 1 WHERE corpus_version = ? '
            . 'AND chunk_id IN (' . $placeholders . ')',
            array_merge([$corpusVersion], $chunkIds),
        );
        $this->db->executeStatement(
            'UPDATE clinical_guideline_chunk_embeddings SET active = 1 WHERE corpus_version = ? '
            . 'AND chunk_id IN (' . $placeholders . ')',
            array_merge([$corpusVersion], $chunkIds),
        );
    }

    public function sparseSearch(string $corpusVersion, string $query, int $limit): array
    {
        $tokens = array_slice(DeterministicGuidelineEmbeddingProvider::tokens($query), 0, 8);
        if ($tokens === []) {
            return [];
        }

        $whereConditions = [];
        $scoreExpressions = [];
        $scoreBinds = [];
        $whereBinds = [];
        foreach ($tokens as $token) {
            $whereConditions[] = '(LOWER(section) LIKE ? OR LOWER(chunk_text) LIKE ?)';
            $scoreExpressions[] = 'IF(LOWER(section) LIKE ? OR LOWER(chunk_text) LIKE ?, 1, 0)';
            $scoreBinds[] = '%' . $token . '%';
            $scoreBinds[] = '%' . $token . '%';
            $whereBinds[] = '%' . $token . '%';
            $whereBinds[] = '%' . $token . '%';
        }
        $binds = array_merge($scoreBinds, [$corpusVersion], $whereBinds, [$limit]);

        $rows = $this->db->fetchRecords(
            'SELECT chunk_id, corpus_version, source_title, source_url_or_file, section, chunk_text, citation_json, '
            . '(' . implode(' + ', $scoreExpressions) . ') AS sparse_hits '
            . 'FROM clinical_guideline_chunks WHERE corpus_version = ? AND active = 1 '
            . 'AND (' . implode(' OR ', $whereConditions) . ') '
            . 'HAVING sparse_hits > 0 ORDER BY sparse_hits DESC, chunk_id ASC LIMIT ?',
            $binds,
        );

        return array_map(
            fn (array $row): GuidelineSearchCandidate => new GuidelineSearchCandidate(
                $this->hydrateChunk(StringKeyedArray::filter($row)),
                sparseScore: round($this->floatValue($row['sparse_hits'] ?? 0) / max(1, count($tokens)), 6),
            ),
            $rows,
        );
    }

    public function denseSearch(string $corpusVersion, array $queryEmbedding, int $limit): array
    {
        $rows = $this->db->fetchRecords(
            'SELECT c.chunk_id, c.corpus_version, c.source_title, c.source_url_or_file, c.section, c.chunk_text, '
            . 'c.citation_json, (1 - VEC_DISTANCE_COSINE(e.embedding, VEC_FromText(?))) AS dense_score '
            . 'FROM clinical_guideline_chunk_embeddings e '
            . 'INNER JOIN clinical_guideline_chunks c ON c.corpus_version = e.corpus_version AND c.chunk_id = e.chunk_id '
            . 'WHERE c.corpus_version = ? AND c.active = 1 AND e.active = 1 '
            . 'ORDER BY dense_score DESC LIMIT ?',
            [self::vectorLiteral($queryEmbedding), $corpusVersion, $limit],
        );

        return array_map(
            fn (array $row): GuidelineSearchCandidate => new GuidelineSearchCandidate(
                $this->hydrateChunk(StringKeyedArray::filter($row)),
                denseScore: round($this->floatValue($row['dense_score'] ?? 0), 6),
            ),
            $rows,
        );
    }

    public function findActiveByVersion(string $corpusVersion): array
    {
        return array_map(
            fn (array $row): GuidelineChunk => $this->hydrateChunk(StringKeyedArray::filter($row)),
            $this->db->fetchRecords(
                'SELECT chunk_id, corpus_version, source_title, source_url_or_file, section, chunk_text, citation_json '
                . 'FROM clinical_guideline_chunks WHERE corpus_version = ? AND active = 1 ORDER BY chunk_id ASC',
                [$corpusVersion],
            ),
        );
    }

    /** @param list<float> $vector */
    public static function vectorLiteral(array $vector): string
    {
        return '[' . implode(',', array_map(static fn (float $value): string => sprintf('%.8F', $value), $vector)) . ']';
    }

    /** @param array<string, mixed> $row */
    private function hydrateChunk(array $row): GuidelineChunk
    {
        $citation = [];
        if (isset($row['citation_json']) && is_string($row['citation_json'])) {
            $decoded = json_decode($row['citation_json'], true);
            $citation = is_array($decoded) ? StringKeyedArray::filter($decoded) : [];
        }

        return new GuidelineChunk(
            $this->stringValue($row['chunk_id'] ?? ''),
            $this->stringValue($row['corpus_version'] ?? ''),
            $this->stringValue($row['source_title'] ?? ''),
            $this->stringValue($row['source_url_or_file'] ?? ''),
            $this->stringValue($row['section'] ?? ''),
            $this->stringValue($row['chunk_text'] ?? ''),
            $citation,
        );
    }

    private function stringValue(mixed $value): string
    {
        if (is_string($value)) {
            return $value;
        }
        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }

        return '';
    }

    private function floatValue(mixed $value): float
    {
        if (is_int($value) || is_float($value)) {
            return (float) $value;
        }
        if (is_string($value) && is_numeric($value)) {
            return (float) $value;
        }

        return 0.0;
    }
}
