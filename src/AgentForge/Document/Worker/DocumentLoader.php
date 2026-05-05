<?php

/**
 * Loads a source document for worker processing.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\AgentForge\Document\Worker;

use OpenEMR\AgentForge\Document\DocumentId;

interface DocumentLoader
{
    /** @throws DocumentLoadException */
    public function load(DocumentId $documentId): DocumentLoadResult;
}
