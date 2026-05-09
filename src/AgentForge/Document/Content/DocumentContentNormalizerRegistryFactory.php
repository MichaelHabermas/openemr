<?php

/**
 * Builds the default content-normalization graph for runtime extraction.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\AgentForge\Document\Content;

use OpenEMR\AgentForge\Document\Extraction\PdfPageRenderer;
use OpenEMR\AgentForge\Time\SystemMonotonicClock;

final class DocumentContentNormalizerRegistryFactory
{
    public static function default(
        PdfPageRenderer $pdfRenderer,
        int $maxPdfPages,
        int $maxTiffSourceBytes = 10_485_760,
        int $maxDocxSourceBytes = 10_485_760,
        int $maxXlsxSourceBytes = 10_485_760,
    ): DocumentContentNormalizerRegistry
    {
        return self::withTiffRenderer($pdfRenderer, $maxPdfPages, new ImagickTiffRasterRenderer(), $maxTiffSourceBytes, $maxDocxSourceBytes, $maxXlsxSourceBytes);
    }

    public static function withTiffRenderer(
        PdfPageRenderer $pdfRenderer,
        int $maxPages,
        RasterDocumentRenderer $tiffRenderer,
        int $maxTiffSourceBytes = 10_485_760,
        int $maxDocxSourceBytes = 10_485_760,
        int $maxXlsxSourceBytes = 10_485_760,
    ): DocumentContentNormalizerRegistry
    {
        $clock = new SystemMonotonicClock();

        return new DocumentContentNormalizerRegistry([
            new PdfDocumentContentNormalizer(new PdfPageRasterRenderer($pdfRenderer), $clock, $maxPages),
            new ImageDocumentContentNormalizer($clock),
            new TiffDocumentContentNormalizer($tiffRenderer, $clock, $maxPages, $maxTiffSourceBytes),
            new DocxDocumentContentNormalizer($clock, $maxDocxSourceBytes),
            new XlsxDocumentContentNormalizer($clock, $maxXlsxSourceBytes),
        ]);
    }
}
