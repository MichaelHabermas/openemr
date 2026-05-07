<?php

/**
 * Review payload for an AgentForge cited source document location.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\AgentForge\Document\SourceReview;

final readonly class DocumentCitationReview
{
    /**
     * @param array{x: float, y: float, width: float, height: float}|null $boundingBox
     */
    public function __construct(
        public int $documentId,
        public int $jobId,
        public ?int $factId,
        public string $documentUrl,
        public string $pageImageUrl,
        public string $pageOrSection,
        public ?int $pageNumber,
        public string $fieldOrChunkId,
        public string $quoteOrValue,
        public ?array $boundingBox,
    ) {
    }

    /**
     * @return array{
     *     document_id: int,
     *     job_id: int,
     *     fact_id: ?int,
     *     document_url: string,
     *     page_image_url: string,
     *     page_or_section: string,
     *     page_number: ?int,
     *     field_or_chunk_id: string,
     *     quote_or_value: string,
     *     review_mode: string,
     *     bounding_box?: array{x: float, y: float, width: float, height: float}
     * }
     */
    public function toArray(): array
    {
        $out = [
            'document_id' => $this->documentId,
            'job_id' => $this->jobId,
            'fact_id' => $this->factId,
            'document_url' => $this->documentUrl,
            'page_image_url' => $this->pageImageUrl,
            'page_or_section' => $this->pageOrSection,
            'page_number' => $this->pageNumber,
            'field_or_chunk_id' => $this->fieldOrChunkId,
            'quote_or_value' => $this->quoteOrValue,
            'review_mode' => $this->boundingBox === null ? 'page_quote_fallback' : 'bounding_box',
        ];

        if ($this->boundingBox !== null) {
            $out['bounding_box'] = $this->boundingBox;
        }

        return $out;
    }
}
