<?php

/**
 * Normalizes single image documents into rendered page content.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\AgentForge\Document\Content;

use OpenEMR\AgentForge\Deadline;
use OpenEMR\AgentForge\Time\MonotonicClock;

final readonly class ImageDocumentContentNormalizer implements DocumentContentNormalizer
{
    private const SUPPORTED_MIME_TYPES = ['image/png', 'image/jpeg', 'image/webp'];

    public function __construct(private MonotonicClock $clock)
    {
    }

    public function supports(DocumentContentNormalizationRequest $request): bool
    {
        return in_array($request->document->mimeType, self::SUPPORTED_MIME_TYPES, true);
    }

    public function normalize(
        DocumentContentNormalizationRequest $request,
        Deadline $deadline,
    ): NormalizedDocumentContent {
        if ($deadline->exceeded()) {
            throw new DocumentContentNormalizationException('Deadline exceeded before image content normalization.');
        }

        $started = $this->clock->nowMs();
        $elapsed = max(0, $this->clock->nowMs() - $started);

        return new NormalizedDocumentContent(
            NormalizedDocumentSource::fromLoadResult($request->document, $request->documentType),
            renderedPages: [
                new NormalizedRenderedPage(1, $request->document->mimeType, $request->document->bytes),
            ],
            normalizer: $this->name(),
            normalizationElapsedMs: $elapsed,
        );
    }

    public function name(): string
    {
        return 'image';
    }
}
