<?php

/**
 * SQL-backed vector persistence for patient document facts.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\AgentForge\Document\Embedding;

use DomainException;
use OpenEMR\AgentForge\DatabaseExecutor;
use OpenEMR\AgentForge\Guidelines\SqlGuidelineChunkRepository;

final readonly class SqlDocumentFactEmbeddingRepository implements DocumentFactEmbeddingRepository
{
    public function __construct(private DatabaseExecutor $executor)
    {
    }

    public function upsert(int $factId, string $factText, EmbeddingProvider $provider): void
    {
        if ($factId <= 0) {
            throw new DomainException('Document fact id must be positive for embedding.');
        }

        $embedding = $provider->embed($factText);
        if (count($embedding) !== 1536) {
            throw new DomainException('Document fact embedding must contain exactly 1536 dimensions.');
        }

        $this->executor->executeStatement(
            'INSERT INTO clinical_document_fact_embeddings '
            . '(fact_id, embedding, embedding_model, active, created_at) '
            . 'VALUES (?, VEC_FromText(?), ?, 1, NOW()) '
            . 'ON DUPLICATE KEY UPDATE embedding = VALUES(embedding), active = 1, created_at = VALUES(created_at)',
            [
                $factId,
                SqlGuidelineChunkRepository::vectorLiteral($embedding),
                $provider->model(),
            ],
        );
    }

    public function deactivateByDocument(int $documentId): void
    {
        if ($documentId <= 0) {
            throw new DomainException('Document id must be positive for embedding deactivation.');
        }

        $this->executor->executeStatement(
            'UPDATE clinical_document_fact_embeddings e '
            . 'INNER JOIN clinical_document_facts f ON f.id = e.fact_id '
            . 'SET e.active = 0 '
            . 'WHERE f.document_id = ?',
            [$documentId],
        );
    }
}
