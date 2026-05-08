<?php

/**
 * Boundary for turning a clinical source document job into derived AgentForge state.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\AgentForge\Document;

use OpenEMR\AgentForge\Document\Worker\DocumentLoadResult;
use OpenEMR\AgentForge\Document\Worker\ProcessingResult;

interface ClinicalDocumentIngestionWorkflow
{
    public function ingest(DocumentJob $job, DocumentLoadResult $document): ProcessingResult;
}
