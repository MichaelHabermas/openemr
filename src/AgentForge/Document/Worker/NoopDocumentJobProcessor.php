<?php

/**
 * M3 placeholder processor. Real extraction belongs to M4.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\AgentForge\Document\Worker;

use OpenEMR\AgentForge\Document\DocumentJob;

final readonly class NoopDocumentJobProcessor implements DocumentJobProcessor
{
    public function process(DocumentJob $job, DocumentLoadResult $document): ProcessingResult
    {
        return ProcessingResult::failed(
            'extraction_not_implemented',
            'M3 worker skeleton; M4 will replace this processor.',
        );
    }
}
