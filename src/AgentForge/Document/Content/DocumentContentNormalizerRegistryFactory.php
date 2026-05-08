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
    public static function default(PdfPageRenderer $pdfRenderer, int $maxPdfPages): DocumentContentNormalizerRegistry
    {
        $clock = new SystemMonotonicClock();

        return new DocumentContentNormalizerRegistry([
            new PdfDocumentContentNormalizer($pdfRenderer, $clock, $maxPdfPages),
            new ImageDocumentContentNormalizer($clock),
        ]);
    }
}
