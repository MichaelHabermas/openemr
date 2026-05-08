<?php

/**
 * Normalizes PDF documents into rendered page content.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\AgentForge\Document\Content;

use OpenEMR\AgentForge\Deadline;
use OpenEMR\AgentForge\Document\Extraction\ExtractionProviderException;
use OpenEMR\AgentForge\Document\Extraction\PdfPageRenderer;
use OpenEMR\AgentForge\Document\ExtractionErrorCode;
use OpenEMR\AgentForge\Time\MonotonicClock;

final readonly class PdfDocumentContentNormalizer implements DocumentContentNormalizer
{
    public function __construct(
        private PdfPageRenderer $renderer,
        private MonotonicClock $clock,
        private int $maxPages,
    ) {
    }

    public function supports(DocumentContentNormalizationRequest $request): bool
    {
        return $request->document->mimeType === 'application/pdf';
    }

    public function normalize(
        DocumentContentNormalizationRequest $request,
        Deadline $deadline,
    ): NormalizedDocumentContent {
        if ($deadline->exceeded()) {
            throw new DocumentContentNormalizationException('Deadline exceeded before PDF content normalization.');
        }

        $started = $this->clock->nowMs();
        try {
            $pages = $this->renderer->render($request->document->bytes, $this->maxPages);
        } catch (ExtractionProviderException $exception) {
            throw new DocumentContentNormalizationException(
                'PDF content normalization failed.',
                ExtractionErrorCode::NormalizationFailure,
                $exception,
            );
        }
        $elapsed = max(0, $this->clock->nowMs() - $started);

        return new NormalizedDocumentContent(
            NormalizedDocumentSource::fromLoadResult($request->document, $request->documentType),
            renderedPages: array_map(
                static fn ($page): NormalizedRenderedPage => new NormalizedRenderedPage(
                    $page->pageNumber,
                    $page->mimeType,
                    $page->bytes,
                ),
                $pages,
            ),
            normalizer: $this->name(),
            normalizationElapsedMs: $elapsed,
        );
    }

    public function name(): string
    {
        return 'pdf';
    }
}
