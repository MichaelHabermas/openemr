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
        public ReviewLocator $locator,
    ) {
    }

    /**
     * @return array{
     *     document_id: int,
     *     job_id: int,
     *     fact_id: ?int,
     *     document_url: string,
     *     page_or_section: string,
     *     page_number: ?int,
     *     field_or_chunk_id: string,
     *     quote_or_value: string,
     *     locator: array<string, mixed>,
     *     page_image_url?: string,
     * }
     */
    public function toArray(): array
    {
        $out = [
            'document_id' => $this->documentId,
            'job_id' => $this->jobId,
            'fact_id' => $this->factId,
            'document_url' => $this->documentUrl,
            'page_or_section' => $this->pageOrSection,
            'page_number' => $this->pageNumber,
            'field_or_chunk_id' => $this->fieldOrChunkId,
            'quote_or_value' => $this->quoteOrValue,
            'locator' => $this->locator->toArray(),
        ];

        if ($this->pageImageUrl !== '') {
            $out['page_image_url'] = $this->pageImageUrl;
        }

        return $out;
    }
}
