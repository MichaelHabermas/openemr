<?php

/**
 * Imagick-backed PDF page renderer for OpenAI VLM extraction.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\AgentForge\Document\Extraction;

use OpenEMR\AgentForge\Document\ExtractionErrorCode;

final class ImagickPdfPageRenderer implements PdfPageRenderer
{
    /**
     * @return list<RenderedPdfPage>
     */
    public function render(string $pdfBytes, int $maxPages): array
    {
        if (!extension_loaded('imagick') || !class_exists(\Imagick::class)) {
            throw new ExtractionProviderException(
                'PDF rendering requires the Imagick extension.',
                ExtractionErrorCode::ExtractionFailure,
            );
        }

        try {
            $imagick = new \Imagick();
            $imagick->setResolution(150, 150);
            $imagick->readImageBlob($pdfBytes);
            $imagick->setImageFormat('png');

            $pages = [];
            $pageNumber = 1;
            foreach ($imagick as $page) {
                if ($pageNumber > $maxPages) {
                    break;
                }

                $page->setImageFormat('png');
                $page->setImageAlphaChannel(\Imagick::ALPHACHANNEL_REMOVE);
                $pages[] = new RenderedPdfPage($pageNumber, 'image/png', $page->getImageBlob());
                ++$pageNumber;
            }

            $imagick->clear();
            $imagick->destroy();
        } catch (\ImagickException $exception) {
            throw new ExtractionProviderException(
                'PDF rendering failed.',
                ExtractionErrorCode::ExtractionFailure,
                $exception,
            );
        }

        if ($pages === []) {
            throw new ExtractionProviderException(
                'PDF rendering produced no pages.',
                ExtractionErrorCode::ExtractionFailure,
            );
        }

        return $pages;
    }
}
