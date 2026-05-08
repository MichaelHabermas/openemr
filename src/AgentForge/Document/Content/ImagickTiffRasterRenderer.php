<?php

/**
 * Imagick-backed TIFF renderer for multipage fax packets.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\AgentForge\Document\Content;

use OpenEMR\AgentForge\Document\Extraction\ExtractionProviderException;
use OpenEMR\AgentForge\Document\ExtractionErrorCode;

final class ImagickTiffRasterRenderer implements RasterDocumentRenderer
{
    public function render(string $bytes, int $maxPages): array
    {
        if (!extension_loaded('imagick') || !class_exists(\Imagick::class)) {
            throw new ExtractionProviderException(
                'TIFF rendering requires the Imagick extension.',
                ExtractionErrorCode::NormalizationFailure,
            );
        }

        try {
            $imagick = new \Imagick();
            $imagick->readImageBlob($bytes);

            $pages = [];
            $pageNumber = 1;
            foreach ($imagick as $page) {
                if ($pageNumber > $maxPages) {
                    break;
                }

                $page->setImageFormat('png');
                $page->setImageAlphaChannel(\Imagick::ALPHACHANNEL_REMOVE);
                $pages[] = new RenderedDocumentPage($pageNumber, 'image/png', $page->getImageBlob());
                ++$pageNumber;
            }

            $imagick->clear();
            $imagick->destroy();
        } catch (\ImagickException $exception) {
            throw new ExtractionProviderException(
                'TIFF rendering failed.',
                ExtractionErrorCode::NormalizationFailure,
                $exception,
            );
        }

        if ($pages === []) {
            throw new ExtractionProviderException(
                'TIFF rendering produced no pages.',
                ExtractionErrorCode::NormalizationFailure,
            );
        }

        return $pages;
    }
}
