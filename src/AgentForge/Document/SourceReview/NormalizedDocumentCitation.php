<?php

/**
 * Normalized citation metadata for source review and evidence.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\AgentForge\Document\SourceReview;

final readonly class NormalizedDocumentCitation
{
    /**
     * @param array{x: float, y: float, width: float, height: float}|null $boundingBox
     */
    public function __construct(
        public string $sourceType,
        public string $sourceId,
        public string $pageOrSection,
        public ?int $pageNumber,
        public string $fieldOrChunkId,
        public string $quoteOrValue,
        public ?array $boundingBox,
    ) {
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        $out = [
            'source_type' => $this->sourceType,
            'source_id' => $this->sourceId,
            'page_or_section' => $this->pageOrSection,
            'field_or_chunk_id' => $this->fieldOrChunkId,
            'quote_or_value' => $this->quoteOrValue,
        ];
        if ($this->boundingBox !== null) {
            $out['bounding_box'] = $this->boundingBox;
        }

        return $out;
    }
}
