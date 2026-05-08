<?php

/**
 * Format-neutral rendered page image for document normalization.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\AgentForge\Document\Content;

use DomainException;

final readonly class RenderedDocumentPage
{
    public function __construct(
        public int $pageNumber,
        public string $mimeType,
        public string $bytes,
    ) {
        if ($pageNumber < 1) {
            throw new DomainException('Rendered document page number must be positive.');
        }
        if ($mimeType === '' || !str_starts_with($mimeType, 'image/')) {
            throw new DomainException('Rendered document page MIME type must be an image.');
        }
        if ($bytes === '') {
            throw new DomainException('Rendered document page bytes must not be empty.');
        }
    }

    public function normalized(): NormalizedRenderedPage
    {
        return new NormalizedRenderedPage($this->pageNumber, $this->mimeType, $this->bytes);
    }
}
