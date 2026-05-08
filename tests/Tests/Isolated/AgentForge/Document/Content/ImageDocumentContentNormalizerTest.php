<?php

/**
 * Isolated tests for image document content normalization.
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
use OpenEMR\AgentForge\Document\Content\ImageDocumentContentNormalizer;
use OpenEMR\AgentForge\Document\DocumentId;
use OpenEMR\AgentForge\Document\DocumentType;
use OpenEMR\AgentForge\Document\Worker\DocumentLoadResult;
use OpenEMR\Tests\Isolated\AgentForge\Support\TickingMonotonicClock;
use PHPUnit\Framework\TestCase;

final class ImageDocumentContentNormalizerTest extends TestCase
{
    public function testImageNormalizerPreservesCurrentDataUrlBehavior(): void
    {
        $clock = new TickingMonotonicClock([200, 205]);
        $normalizer = new ImageDocumentContentNormalizer($clock);
        $document = new DocumentLoadResult('png-bytes', 'image/png', 'intake.png');
        $request = new DocumentContentNormalizationRequest(new DocumentId(8), DocumentType::IntakeForm, $document);

        $content = $normalizer->normalize($request, new Deadline(new TickingMonotonicClock([200]), 1_000));

        $this->assertTrue($normalizer->supports($request));
        $this->assertSame(hash('sha256', $document->bytes), $content->source->sha256);
        $this->assertSame('image/png', $content->source->mimeType);
        $this->assertSame('data:image/png;base64,cG5nLWJ5dGVz', $content->renderedPages[0]->dataUrl());
        $this->assertSame('image', $content->telemetry()->normalizer);
        $this->assertSame(5, $content->telemetry()->normalizationElapsedMs);
    }

    public function testUnsupportedImageMimeDoesNotSupportRequest(): void
    {
        $normalizer = new ImageDocumentContentNormalizer(new TickingMonotonicClock([1]));
        $request = new DocumentContentNormalizationRequest(
            new DocumentId(9),
            DocumentType::IntakeForm,
            new DocumentLoadResult('gif-bytes', 'image/gif', 'scan.gif'),
        );

        $this->assertFalse($normalizer->supports($request));
    }

    public function testImageNormalizerFailsBeforeWorkWhenDeadlineExceeded(): void
    {
        $normalizer = new ImageDocumentContentNormalizer(new TickingMonotonicClock([1]));
        $request = new DocumentContentNormalizationRequest(
            new DocumentId(9),
            DocumentType::IntakeForm,
            new DocumentLoadResult('png-bytes', 'image/png', 'scan.png'),
        );

        $this->expectException(DocumentContentNormalizationException::class);
        $this->expectExceptionMessage('Deadline exceeded before image content normalization.');

        $normalizer->normalize($request, new Deadline(new TickingMonotonicClock([0, 100]), 1));
    }
}
