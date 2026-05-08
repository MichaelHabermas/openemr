<?php

/**
 * Normalizes multipage TIFF fax packets into rendered page content.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\AgentForge\Document\Content;

use OpenEMR\AgentForge\Deadline;
use OpenEMR\AgentForge\Document\DocumentType;
use OpenEMR\AgentForge\Document\Extraction\ExtractionProviderException;
use OpenEMR\AgentForge\Document\ExtractionErrorCode;
use OpenEMR\AgentForge\Time\MonotonicClock;

final readonly class TiffDocumentContentNormalizer implements DocumentContentNormalizer
{
    private const SUPPORTED_MIME_TYPES = ['image/tiff', 'image/tif'];

    public function __construct(
        private RasterDocumentRenderer $renderer,
        private MonotonicClock $clock,
        private int $maxPages,
        private int $maxSourceBytes = 10_485_760,
    ) {
    }

    public function supports(DocumentContentNormalizationRequest $request): bool
    {
        return $request->documentType === DocumentType::FaxPacket
            && in_array($request->document->mimeType, self::SUPPORTED_MIME_TYPES, true);
    }

    public function normalize(
        DocumentContentNormalizationRequest $request,
        Deadline $deadline,
    ): NormalizedDocumentContent {
        if ($deadline->exceeded()) {
            throw new DocumentContentNormalizationException('Deadline exceeded before TIFF content normalization.');
        }
        if ($request->document->byteCount > $this->maxSourceBytes) {
            throw new DocumentContentNormalizationException(
                'TIFF content normalization failed.',
                ExtractionErrorCode::NormalizationFailure,
            );
        }

        $started = $this->clock->nowMs();
        try {
            $pages = $this->renderer->render($request->document->bytes, $this->maxPages);
        } catch (ExtractionProviderException $exception) {
            throw new DocumentContentNormalizationException(
                'TIFF content normalization failed.',
                ExtractionErrorCode::NormalizationFailure,
                $exception,
            );
        }
        $elapsed = max(0, $this->clock->nowMs() - $started);

        return new NormalizedDocumentContent(
            NormalizedDocumentSource::fromLoadResult($request->document, $request->documentType),
            renderedPages: array_map(
                static fn (RenderedDocumentPage $page): NormalizedRenderedPage => $page->normalized(),
                $pages,
            ),
            normalizer: $this->name(),
            normalizationElapsedMs: $elapsed,
        );
    }

    public function name(): string
    {
        return 'tiff';
    }
}
