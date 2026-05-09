<?php

/**
 * Routes extraction requests by document type before invoking a concrete provider.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\AgentForge\Document\Extraction;

use OpenEMR\AgentForge\Deadline;
use OpenEMR\AgentForge\Document\DocumentId;
use OpenEMR\AgentForge\Document\DocumentType;
use OpenEMR\AgentForge\Document\Worker\DocumentLoadResult;

final readonly class DocumentTypeRoutingExtractionProvider implements DocumentExtractionProvider
{
    /** @param array<string, DocumentExtractionProvider> $providersByDocumentType */
    public function __construct(
        private DocumentExtractionProvider $defaultProvider,
        private array $providersByDocumentType,
    ) {
    }

    public function extract(
        DocumentId $documentId,
        DocumentLoadResult $document,
        DocumentType $documentType,
        Deadline $deadline,
    ): ExtractionProviderResponse {
        return ($this->providersByDocumentType[$documentType->value] ?? $this->defaultProvider)
            ->extract($documentId, $document, $documentType, $deadline);
    }
}
