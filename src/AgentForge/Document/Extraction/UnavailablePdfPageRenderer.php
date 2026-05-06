<?php

/**
 * Default renderer that makes PDF rendering dependency explicit.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\AgentForge\Document\Extraction;

final class UnavailablePdfPageRenderer implements PdfPageRenderer
{
    public function render(string $pdfBytes, int $maxPages): array
    {
        throw new ExtractionProviderException('PDF rendering is not configured for OpenAI VLM extraction.');
    }
}
