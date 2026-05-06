<?php

/**
 * Isolated tests for Imagick-backed PDF page rendering.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/open-emr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Tests\Isolated\AgentForge\Document\Extraction;

use OpenEMR\AgentForge\Document\Extraction\ExtractionProviderException;
use OpenEMR\AgentForge\Document\Extraction\ImagickPdfPageRenderer;
use PHPUnit\Framework\TestCase;

final class ImagickPdfPageRendererTest extends TestCase
{
    public function testThrowsClearErrorWhenImagickExtensionUnavailable(): void
    {
        if (extension_loaded('imagick') && class_exists(\Imagick::class)) {
            $this->markTestSkipped('Imagick is available; missing-extension branch is not testable here.');
        }

        $this->expectException(ExtractionProviderException::class);
        $this->expectExceptionMessage('Imagick');

        (new ImagickPdfPageRenderer())->render('%PDF-1.4 minimal', 1);
    }

    public function testRendersAtLeastOnePngPageWhenImagickAvailable(): void
    {
        if (!extension_loaded('imagick') || !class_exists(\Imagick::class)) {
            $this->markTestSkipped('Imagick extension not loaded.');
        }

        $pdf = '%PDF-1.4
1 0 obj<</Type/Catalog/Pages 2 0 R>>endobj
2 0 obj<</Type/Pages/Kids[3 0 R]/Count 1>>endobj
3 0 obj<</Type/Page/MediaBox[0 0 200 200]/Parent 2 0 R>>endobj
xref
0 4
0000000000 65535 f 
0000000009 00000 n 
0000000052 00000 n 
0000000101 00000 n 
trailer<</Size 4/Root 1 0 R>>
startxref
178
%%EOF';

        $pages = (new ImagickPdfPageRenderer())->render($pdf, 2);
        $this->assertNotEmpty($pages);
        $this->assertSame(1, $pages[0]->pageNumber);
        $this->assertSame('image/png', $pages[0]->mimeType);
        $this->assertNotSame('', $pages[0]->bytes);
    }
}
