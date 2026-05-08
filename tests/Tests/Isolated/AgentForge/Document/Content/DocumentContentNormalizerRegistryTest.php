<?php

/**
 * Isolated tests for document content normalizer selection.
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
use OpenEMR\AgentForge\Document\Content\DocumentContentNormalizer;
use OpenEMR\AgentForge\Document\Content\DocumentContentNormalizerRegistry;
use OpenEMR\AgentForge\Document\Content\DocumentContentNormalizerRegistryFactory;
use OpenEMR\AgentForge\Document\Content\NormalizedDocumentContent;
use OpenEMR\AgentForge\Document\Content\NormalizedDocumentSource;
use OpenEMR\AgentForge\Document\Content\NormalizedRenderedPage;
use OpenEMR\AgentForge\Document\Content\RasterDocumentRenderer;
use OpenEMR\AgentForge\Document\Content\RenderedDocumentPage;
use OpenEMR\AgentForge\Document\DocumentId;
use OpenEMR\AgentForge\Document\DocumentType;
use OpenEMR\AgentForge\Document\Extraction\PdfPageRenderer;
use OpenEMR\AgentForge\Document\Extraction\RenderedPdfPage;
use OpenEMR\AgentForge\Document\ExtractionErrorCode;
use OpenEMR\AgentForge\Document\Worker\DocumentLoadResult;
use OpenEMR\Tests\Isolated\AgentForge\Support\AgentForgeTestFixtures;
use PHPUnit\Framework\TestCase;

final class DocumentContentNormalizerRegistryTest extends TestCase
{
    public function testRegistryUsesFirstSupportingNormalizer(): void
    {
        $request = new DocumentContentNormalizationRequest(
            new DocumentId(10),
            DocumentType::LabPdf,
            new DocumentLoadResult('bytes', 'application/pdf', 'lab.pdf'),
        );
        $first = new RegistryTestNormalizer('first', false);
        $second = new RegistryTestNormalizer('second', true);
        $third = new RegistryTestNormalizer('third', true);

        $content = (new DocumentContentNormalizerRegistry([$first, $second, $third]))->normalize(
            $request,
            new Deadline(AgentForgeTestFixtures::frozenMonotonicClock(1_000), 1_000),
        );

        $this->assertSame('second', $content->telemetry()->normalizer);
        $this->assertSame(0, $first->normalizeCalls);
        $this->assertSame(1, $second->normalizeCalls);
        $this->assertSame(0, $third->normalizeCalls);
    }

    public function testUnsupportedMimeFailureIsStableAndSafe(): void
    {
        $request = new DocumentContentNormalizationRequest(
            new DocumentId(10),
            DocumentType::LabPdf,
            new DocumentLoadResult('bytes', 'text/plain', 'note.txt'),
        );

        try {
            (new DocumentContentNormalizerRegistry([]))->normalize(
                $request,
                new Deadline(AgentForgeTestFixtures::frozenMonotonicClock(1_000), 1_000),
            );
            $this->fail('Expected unsupported MIME type to fail.');
        } catch (DocumentContentNormalizationException $exception) {
            $this->assertSame(ExtractionErrorCode::UnsupportedMimeType, $exception->errorCode);
            $this->assertStringContainsString('text/plain', $exception->getMessage());
            $this->assertStringNotContainsString('bytes', $exception->getMessage());
            $this->assertStringNotContainsString('note.txt', $exception->getMessage());
        }
    }

    public function testFactoryRegistrySupportsPdfImagesAndInjectedTiffRenderer(): void
    {
        $registry = DocumentContentNormalizerRegistryFactory::withTiffRenderer(
            new RegistryFactoryPdfRenderer(),
            3,
            new RegistryFactoryTiffRenderer(),
            1024,
        );

        $deadline = new Deadline(AgentForgeTestFixtures::frozenMonotonicClock(1_000), 1_000);
        $pdf = $registry->normalize(new DocumentContentNormalizationRequest(
            new DocumentId(11),
            DocumentType::LabPdf,
            new DocumentLoadResult('%PDF', 'application/pdf', 'lab.pdf'),
        ), $deadline);
        $image = $registry->normalize(new DocumentContentNormalizationRequest(
            new DocumentId(12),
            DocumentType::IntakeForm,
            new DocumentLoadResult('png', 'image/png', 'scan.png'),
        ), $deadline);
        $tiff = $registry->normalize(new DocumentContentNormalizationRequest(
            new DocumentId(13),
            DocumentType::FaxPacket,
            new DocumentLoadResult('tiff', 'image/tiff', 'fax.tiff'),
        ), $deadline);

        $this->assertSame('pdf', $pdf->telemetry()->normalizer);
        $this->assertSame('image', $image->telemetry()->normalizer);
        $this->assertSame('tiff', $tiff->telemetry()->normalizer);
        $this->assertSame('data:image/png;base64,dGlmZi1wYWdl', $tiff->renderedPages[0]->dataUrl());
    }

    public function testDefaultFactoryRegistrySupportsTiffWhenImagickAvailable(): void
    {
        if (!extension_loaded('imagick') || !class_exists(\Imagick::class)) {
            $this->markTestSkipped('Imagick extension not loaded.');
        }

        $tiff = file_get_contents(__DIR__ . '/../../../../../../../agent-forge/docs/example-documents/tiff/p01-chen-fax-packet.tiff');
        $this->assertIsString($tiff);
        $registry = DocumentContentNormalizerRegistryFactory::default(new RegistryFactoryPdfRenderer(), 1);

        $content = $registry->normalize(new DocumentContentNormalizationRequest(
            new DocumentId(14),
            DocumentType::FaxPacket,
            new DocumentLoadResult($tiff, 'image/tiff', 'fax.tiff'),
        ), new Deadline(AgentForgeTestFixtures::frozenMonotonicClock(1_000), 1_000));

        $this->assertSame('tiff', $content->telemetry()->normalizer);
        $this->assertSame(1, $content->telemetry()->renderedPageCount);
        $this->assertStringStartsWith('data:image/png;base64,', $content->renderedPages[0]->dataUrl());
    }
}

final class RegistryTestNormalizer implements DocumentContentNormalizer
{
    public int $normalizeCalls = 0;

    public function __construct(
        private readonly string $name,
        private readonly bool $supports,
    ) {
    }

    public function supports(DocumentContentNormalizationRequest $request): bool
    {
        return $this->supports;
    }

    public function normalize(
        DocumentContentNormalizationRequest $request,
        Deadline $deadline,
    ): NormalizedDocumentContent {
        ++$this->normalizeCalls;

        return new NormalizedDocumentContent(
            NormalizedDocumentSource::fromLoadResult($request->document, $request->documentType),
            renderedPages: [new NormalizedRenderedPage(1, 'image/png', 'page')],
            normalizer: $this->name,
        );
    }

    public function name(): string
    {
        return $this->name;
    }
}

final class RegistryFactoryPdfRenderer implements PdfPageRenderer
{
    /** @return list<RenderedPdfPage> */
    public function render(string $pdfBytes, int $maxPages): array
    {
        return [new RenderedPdfPage(1, 'image/png', 'pdf-page')];
    }
}

final class RegistryFactoryTiffRenderer implements RasterDocumentRenderer
{
    /** @return list<RenderedDocumentPage> */
    public function render(string $bytes, int $maxPages): array
    {
        return [new RenderedDocumentPage(1, 'image/png', 'tiff-page')];
    }
}
