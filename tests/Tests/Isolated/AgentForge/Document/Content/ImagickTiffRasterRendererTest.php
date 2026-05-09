<?php

/**
 * Isolated tests for Imagick-backed TIFF page rendering.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Tests\Isolated\AgentForge\Document\Content;

use OpenEMR\AgentForge\Document\Content\ImagickTiffRasterRenderer;
use OpenEMR\AgentForge\Document\Extraction\ExtractionProviderException;
use PHPUnit\Framework\TestCase;

final class ImagickTiffRasterRendererTest extends TestCase
{
    public function testThrowsClearErrorWhenImagickExtensionUnavailable(): void
    {
        if (extension_loaded('imagick') && class_exists(\Imagick::class)) {
            $this->markTestSkipped('Imagick is available; missing-extension branch is not testable here.');
        }

        $this->expectException(ExtractionProviderException::class);
        $this->expectExceptionMessage('Imagick');

        (new ImagickTiffRasterRenderer())->render('not-a-tiff', 1);
    }

    public function testRendersMultipageTiffAsBoundedPngPagesWhenImagickAvailable(): void
    {
        if (!extension_loaded('imagick') || !class_exists(\Imagick::class)) {
            $this->markTestSkipped('Imagick extension not loaded.');
        }

        $tiff = file_get_contents(__DIR__ . '/../../../../../../agent-forge/docs/example-documents/tiff/p01-chen-fax-packet.tiff');
        $this->assertIsString($tiff);

        $pages = (new ImagickTiffRasterRenderer())->render($tiff, 2);

        $this->assertCount(2, $pages);
        $this->assertSame(1, $pages[0]->pageNumber);
        $this->assertSame(2, $pages[1]->pageNumber);
        $this->assertSame('image/png', $pages[0]->mimeType);
        $this->assertSame('image/png', $pages[1]->mimeType);
        $this->assertStringStartsWith("\x89PNG", $pages[0]->bytes);
        $this->assertStringStartsWith("\x89PNG", $pages[1]->bytes);
    }
}
