<?php

/**
 * Rendered page or image input for model-assisted extraction.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\AgentForge\Document\Content;

use DomainException;

final readonly class NormalizedRenderedPage
{
    public function __construct(
        public int $pageNumber,
        public string $mimeType,
        public string $bytes,
        public ?int $width = null,
        public ?int $height = null,
    ) {
        if ($pageNumber < 1) {
            throw new DomainException('Normalized rendered page number must be positive.');
        }
        if ($mimeType === '' || !str_starts_with($mimeType, 'image/')) {
            throw new DomainException('Normalized rendered page MIME type must be an image.');
        }
        if ($bytes === '') {
            throw new DomainException('Normalized rendered page bytes must not be empty.');
        }
        if (($width !== null && $width < 1) || ($height !== null && $height < 1)) {
            throw new DomainException('Normalized rendered page dimensions must be positive when present.');
        }
    }

    public function dataUrl(): string
    {
        return sprintf('data:%s;base64,%s', $this->mimeType, base64_encode($this->bytes));
    }
}
