<?php

/**
 * Persistence boundary for document fact vectors.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\AgentForge\Document\Embedding;

use OpenEMR\AgentForge\Document\DocumentId;

interface DocumentFactEmbeddingRepository
{
    public function upsert(int $factId, string $factText, EmbeddingProvider $provider): void;

    public function deactivateByDocument(DocumentId $documentId): void;
}
