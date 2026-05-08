<?php

/**
 * Input for document content normalization.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\AgentForge\Document\Content;

use OpenEMR\AgentForge\Document\DocumentId;
use OpenEMR\AgentForge\Document\DocumentType;
use OpenEMR\AgentForge\Document\Worker\DocumentLoadResult;

final readonly class DocumentContentNormalizationRequest
{
    public function __construct(
        public DocumentId $documentId,
        public DocumentType $documentType,
        public DocumentLoadResult $document,
    ) {
    }
}
