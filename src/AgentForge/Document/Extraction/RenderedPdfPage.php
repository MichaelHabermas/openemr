<?php

/**
 * Rendered page image for VLM extraction.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\AgentForge\Document\Extraction;

use DomainException;

final readonly class RenderedPdfPage
{
    public function __construct(
        public int $pageNumber,
        public string $mimeType,
        public string $bytes,
    ) {
        if ($pageNumber < 1) {
            throw new DomainException('Rendered PDF page number must be positive.');
        }
        if ($mimeType === '' || !str_starts_with($mimeType, 'image/')) {
            throw new DomainException('Rendered PDF page MIME type must be an image.');
        }
        if ($bytes === '') {
            throw new DomainException('Rendered PDF page bytes must not be empty.');
        }
    }

    public function dataUrl(): string
    {
        return sprintf('data:%s;base64,%s', $this->mimeType, base64_encode($this->bytes));
    }
}
