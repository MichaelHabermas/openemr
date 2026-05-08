<?php

/**
 * Isolated tests for PDF document content normalization.
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
use OpenEMR\AgentForge\Document\Content\PdfDocumentContentNormalizer;
use OpenEMR\AgentForge\Document\DocumentId;
use OpenEMR\AgentForge\Document\DocumentType;
use OpenEMR\AgentForge\Document\Extraction\ExtractionProviderException;
use OpenEMR\AgentForge\Document\Extraction\PdfPageRenderer;
use OpenEMR\AgentForge\Document\Extraction\RenderedPdfPage;
use OpenEMR\AgentForge\Document\ExtractionErrorCode;
use OpenEMR\AgentForge\Document\Worker\DocumentLoadResult;
use OpenEMR\Tests\Isolated\AgentForge\Support\TickingMonotonicClock;
use PHPUnit\Framework\TestCase;

final class PdfDocumentContentNormalizerTest extends TestCase
{
    public function testPdfNormalizerRendersPagesAndPreservesSourceMetadata(): void
    {
        $renderer = new PdfNormalizerTestRenderer();
        $clock = new TickingMonotonicClock([100, 123]);
        $normalizer = new PdfDocumentContentNormalizer($renderer, $clock, 2);
        $document = new DocumentLoadResult('%PDF fixture', 'application/pdf', 'lab.pdf');
        $request = new DocumentContentNormalizationRequest(new DocumentId(7), DocumentType::LabPdf, $document);

        $content = $normalizer->normalize($request, new Deadline(new TickingMonotonicClock([100]), 1_000));

        $this->assertTrue($normalizer->supports($request));
        $this->assertSame('%PDF fixture', $renderer->lastPdfBytes);
        $this->assertSame(2, $renderer->lastMaxPages);
        $this->assertSame(hash('sha256', $document->bytes), $content->source->sha256);
        $this->assertSame('application/pdf', $content->source->mimeType);
        $this->assertSame(strlen($document->bytes), $content->source->byteLength);
        $this->assertSame('data:image/png;base64,cGFnZS0x', $content->renderedPages[0]->dataUrl());
        $this->assertSame('pdf', $content->telemetry()->normalizer);
        $this->assertSame(23, $content->telemetry()->normalizationElapsedMs);
        $this->assertSame([], $content->telemetry()->warningCodes);
    }

    public function testPdfNormalizerFailsBeforeRenderingWhenDeadlineExceeded(): void
    {
        $renderer = new PdfNormalizerTestRenderer();
        $normalizer = new PdfDocumentContentNormalizer($renderer, new TickingMonotonicClock([100]), 2);
        $request = new DocumentContentNormalizationRequest(
            new DocumentId(7),
            DocumentType::LabPdf,
            new DocumentLoadResult('%PDF fixture', 'application/pdf', 'lab.pdf'),
        );

        $this->expectException(DocumentContentNormalizationException::class);
        $this->expectExceptionMessage('Deadline exceeded before PDF content normalization.');

        $normalizer->normalize($request, new Deadline(new TickingMonotonicClock([0, 100]), 1));
    }

    public function testPdfRendererFailureIsMappedToSafeNormalizationFailure(): void
    {
        $normalizer = new PdfDocumentContentNormalizer(
            new PdfNormalizerThrowingRenderer(),
            new TickingMonotonicClock([100, 101]),
            2,
        );
        $request = new DocumentContentNormalizationRequest(
            new DocumentId(7),
            DocumentType::LabPdf,
            new DocumentLoadResult('%PDF Jane Doe', 'application/pdf', 'jane-lab.pdf'),
        );

        try {
            $normalizer->normalize($request, new Deadline(new TickingMonotonicClock([100]), 1_000));
            $this->fail('Expected PDF renderer failure to be wrapped.');
        } catch (DocumentContentNormalizationException $exception) {
            $this->assertSame(ExtractionErrorCode::NormalizationFailure, $exception->errorCode);
            $this->assertSame('PDF content normalization failed.', $exception->getMessage());
            $this->assertStringNotContainsString('Jane Doe', $exception->getMessage());
            $this->assertStringNotContainsString('jane-lab.pdf', $exception->getMessage());
        }
    }
}

final class PdfNormalizerTestRenderer implements PdfPageRenderer
{
    public ?string $lastPdfBytes = null;
    public ?int $lastMaxPages = null;

    /** @return list<RenderedPdfPage> */
    public function render(string $pdfBytes, int $maxPages): array
    {
        $this->lastPdfBytes = $pdfBytes;
        $this->lastMaxPages = $maxPages;

        return [new RenderedPdfPage(1, 'image/png', 'page-1')];
    }
}

final class PdfNormalizerThrowingRenderer implements PdfPageRenderer
{
    /** @return list<RenderedPdfPage> */
    public function render(string $pdfBytes, int $maxPages): array
    {
        throw new ExtractionProviderException('Renderer saw Jane Doe in jane-lab.pdf');
    }
}
