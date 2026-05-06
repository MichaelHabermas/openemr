<?php

/**
 * Persistence boundary for source-document retraction side effects.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\AgentForge\Document\Retraction;

use OpenEMR\AgentForge\Document\DocumentId;
use OpenEMR\AgentForge\Document\DocumentRetractionReason;

interface DocumentRetractionRepository
{
    public function retractByDocument(DocumentId $documentId, DocumentRetractionReason $reason): int;
}
