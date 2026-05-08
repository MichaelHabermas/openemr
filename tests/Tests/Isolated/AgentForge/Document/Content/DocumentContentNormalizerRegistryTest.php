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
use OpenEMR\AgentForge\Document\Content\NormalizedDocumentContent;
use OpenEMR\AgentForge\Document\Content\NormalizedDocumentSource;
use OpenEMR\AgentForge\Document\Content\NormalizedRenderedPage;
use OpenEMR\AgentForge\Document\DocumentId;
use OpenEMR\AgentForge\Document\DocumentType;
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
