<?php

/**
 * Chooses the content normalizer for a raw document.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\AgentForge\Document\Content;

use OpenEMR\AgentForge\Deadline;
use OpenEMR\AgentForge\Document\ExtractionErrorCode;

final readonly class DocumentContentNormalizerRegistry
{
    /** @var list<DocumentContentNormalizer> */
    private array $normalizers;

    /** @param list<DocumentContentNormalizer> $normalizers */
    public function __construct(array $normalizers)
    {
        $this->normalizers = $normalizers;
    }

    public function normalize(
        DocumentContentNormalizationRequest $request,
        Deadline $deadline,
    ): NormalizedDocumentContent {
        foreach ($this->normalizers as $normalizer) {
            if (!$normalizer->supports($request)) {
                continue;
            }

            return $normalizer->normalize($request, $deadline);
        }

        throw new DocumentContentNormalizationException(
            sprintf('Document MIME type "%s" is not supported for content normalization.', $request->document->mimeType),
            ExtractionErrorCode::UnsupportedMimeType,
        );
    }
}
