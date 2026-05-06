<?php

/**
 * Provider boundary for AgentForge clinical document extraction.
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

interface DocumentExtractionProvider
{
    public function extract(
        DocumentId $documentId,
        DocumentLoadResult $document,
        DocumentType $documentType,
        Deadline $deadline,
    ): ExtractionProviderResponse;
}
