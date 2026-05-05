<?php

/**
 * Strategy interface for processing a claimed document job.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\AgentForge\Document\Worker;

use OpenEMR\AgentForge\Document\DocumentJob;

interface DocumentJobProcessor
{
    public function process(DocumentJob $job, DocumentLoadResult $document): ProcessingResult;
}
