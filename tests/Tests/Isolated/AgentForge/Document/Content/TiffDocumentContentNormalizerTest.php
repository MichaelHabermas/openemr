<?php

/**
 * Isolated tests for TIFF fax packet content normalization.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Tests\Isolated\AgentForge\Document\Content;

use OpenEMR\AgentForge\Deadline;
use OpenEMR\AgentForge\Document\Content\DocumentContentNormalizationException;
use OpenEMR\AgentForge\Document\Content\DocumentContentNormalizationRequest;
use OpenEMR\AgentForge\Document\Content\RasterDocumentRenderer;
use OpenEMR\AgentForge\Document\Content\RenderedDocumentPage;
use OpenEMR\AgentForge\Document\Content\TiffDocumentContentNormalizer;
use OpenEMR\AgentForge\Document\DocumentId;
use OpenEMR\AgentForge\Document\DocumentType;
use OpenEMR\AgentForge\Document\Extraction\ExtractionProviderException;
use OpenEMR\AgentForge\Document\ExtractionErrorCode;
use OpenEMR\AgentForge\Document\Worker\DocumentLoadResult;
use OpenEMR\Tests\Isolated\AgentForge\Support\TickingMonotonicClock;
use PHPUnit\Framework\TestCase;

final class TiffDocumentContentNormalizerTest extends TestCase
{
    public function testTiffNormalizerRendersPagesAndPreservesSourceMetadata(): void
    {
        $renderer = new TiffNormalizerTestRenderer([
            new RenderedDocumentPage(1, 'image/png', 'page-1'),
            new RenderedDocumentPage(2, 'image/png', 'page-2'),
        ]);
        $normalizer = new TiffDocumentContentNormalizer($renderer, new TickingMonotonicClock([100, 129]), 4, 1024);
        $document = new DocumentLoadResult('tiff-bytes', 'image/tiff', 'fax.tiff');
        $request = new DocumentContentNormalizationRequest(new DocumentId(9), DocumentType::FaxPacket, $document);

        $content = $normalizer->normalize($request, new Deadline(new TickingMonotonicClock([100]), 1_000));

        $this->assertTrue($normalizer->supports($request));
        $this->assertTrue($normalizer->supports(new DocumentContentNormalizationRequest(
            new DocumentId(10),
            DocumentType::FaxPacket,
            new DocumentLoadResult('tif-bytes', 'image/tif', 'fax.tif'),
        )));
        $this->assertFalse($normalizer->supports(new DocumentContentNormalizationRequest(
            new DocumentId(11),
            DocumentType::LabPdf,
            new DocumentLoadResult('tif-bytes', 'image/tiff', 'lab.tiff'),
        )));
        $this->assertSame('tiff-bytes', $renderer->lastBytes);
        $this->assertSame(4, $renderer->lastMaxPages);
        $this->assertSame(hash('sha256', $document->bytes), $content->source->sha256);
        $this->assertSame('image/tiff', $content->source->mimeType);
        $this->assertSame(strlen($document->bytes), $content->source->byteLength);
        $this->assertSame('data:image/png;base64,cGFnZS0x', $content->renderedPages[0]->dataUrl());
        $this->assertSame('data:image/png;base64,cGFnZS0y', $content->renderedPages[1]->dataUrl());
        $this->assertSame('tiff', $content->telemetry()->normalizer);
        $this->assertSame(2, $content->telemetry()->renderedPageCount);
        $this->assertSame(29, $content->telemetry()->normalizationElapsedMs);
        $this->assertSame([], $content->telemetry()->warningCodes);
    }

    public function testOversizedTiffFailsWithStablePhiSafeErrorBeforeRendering(): void
    {
        $renderer = new TiffNormalizerTestRenderer([new RenderedDocumentPage(1, 'image/png', 'page')]);
        $normalizer = new TiffDocumentContentNormalizer($renderer, new TickingMonotonicClock([100]), 2, 4);
        $request = new DocumentContentNormalizationRequest(
            new DocumentId(9),
            DocumentType::FaxPacket,
            new DocumentLoadResult('Jane Doe TIFF bytes', 'image/tiff', 'jane-fax.tiff'),
        );

        try {
            $normalizer->normalize($request, new Deadline(new TickingMonotonicClock([100]), 1_000));
            $this->fail('Expected oversized TIFF to fail safely.');
        } catch (DocumentContentNormalizationException $exception) {
            $this->assertSame(ExtractionErrorCode::NormalizationFailure, $exception->errorCode);
            $this->assertSame('TIFF content normalization failed.', $exception->getMessage());
            $this->assertSame(0, $renderer->calls);
            $this->assertStringNotContainsString('Jane Doe', $exception->getMessage());
            $this->assertStringNotContainsString('jane-fax.tiff', $exception->getMessage());
        }
    }

    public function testRendererFailureIsMappedToSafeNormalizationFailure(): void
    {
        $normalizer = new TiffDocumentContentNormalizer(
            new TiffNormalizerThrowingRenderer(),
            new TickingMonotonicClock([100, 101]),
            2,
            1024,
        );
        $request = new DocumentContentNormalizationRequest(
            new DocumentId(9),
            DocumentType::FaxPacket,
            new DocumentLoadResult('Jane Doe TIFF bytes', 'image/tiff', 'jane-fax.tiff'),
        );

        try {
            $normalizer->normalize($request, new Deadline(new TickingMonotonicClock([100]), 1_000));
            $this->fail('Expected TIFF renderer failure to be wrapped.');
        } catch (DocumentContentNormalizationException $exception) {
            $this->assertSame(ExtractionErrorCode::NormalizationFailure, $exception->errorCode);
            $this->assertSame('TIFF content normalization failed.', $exception->getMessage());
            $this->assertStringNotContainsString('Jane Doe', $exception->getMessage());
            $this->assertStringNotContainsString('jane-fax.tiff', $exception->getMessage());
        }
    }
}

final class TiffNormalizerTestRenderer implements RasterDocumentRenderer
{
    public int $calls = 0;
    public ?string $lastBytes = null;
    public ?int $lastMaxPages = null;

    /** @param list<RenderedDocumentPage> $pages */
    public function __construct(private readonly array $pages)
    {
    }

    /** @return list<RenderedDocumentPage> */
    public function render(string $bytes, int $maxPages): array
    {
        ++$this->calls;
        $this->lastBytes = $bytes;
        $this->lastMaxPages = $maxPages;

        return $this->pages;
    }
}

final class TiffNormalizerThrowingRenderer implements RasterDocumentRenderer
{
    /** @return list<RenderedDocumentPage> */
    public function render(string $bytes, int $maxPages): array
    {
        throw new ExtractionProviderException('Renderer saw Jane Doe in jane-fax.tiff');
    }
}
