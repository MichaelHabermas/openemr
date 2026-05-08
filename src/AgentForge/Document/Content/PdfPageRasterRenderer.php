<?php

/**
 * Adapts the existing PDF renderer to the generic raster renderer seam.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\AgentForge\Document\Content;

use OpenEMR\AgentForge\Document\Extraction\PdfPageRenderer;

final readonly class PdfPageRasterRenderer implements RasterDocumentRenderer
{
    public function __construct(private PdfPageRenderer $renderer)
    {
    }

    public function render(string $bytes, int $maxPages): array
    {
        return array_map(
            static fn ($page): RenderedDocumentPage => new RenderedDocumentPage(
                $page->pageNumber,
                $page->mimeType,
                $page->bytes,
            ),
            $this->renderer->render($bytes, $maxPages),
        );
    }
}
