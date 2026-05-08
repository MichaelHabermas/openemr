<?php

/**
 * Renders page-oriented document bytes into provider-safe image pages.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\AgentForge\Document\Content;

use OpenEMR\AgentForge\Document\Extraction\ExtractionProviderException;

interface RasterDocumentRenderer
{
    /**
     * @return list<RenderedDocumentPage>
     *
     * @throws ExtractionProviderException
     */
    public function render(string $bytes, int $maxPages): array;
}
