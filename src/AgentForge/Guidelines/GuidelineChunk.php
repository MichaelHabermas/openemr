<?php

/**
 * Versioned clinical guideline corpus chunk.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\AgentForge\Guidelines;

use DomainException;
use OpenEMR\AgentForge\Evidence\EvidenceBundleItem;
use OpenEMR\AgentForge\Evidence\EvidenceText;

final readonly class GuidelineChunk
{
    /**
     * @param array<string, mixed> $citation
     */
    public function __construct(
        public string $chunkId,
        public string $corpusVersion,
        public string $sourceTitle,
        public string $sourceUrlOrFile,
        public string $section,
        public string $chunkText,
        public array $citation,
    ) {
        foreach ([
            'chunk id' => $chunkId,
            'corpus version' => $corpusVersion,
            'source title' => $sourceTitle,
            'source URL or file' => $sourceUrlOrFile,
            'section' => $section,
            'chunk text' => $chunkText,
        ] as $label => $value) {
            if (trim($value) === '') {
                throw new DomainException(sprintf('Guideline %s is required.', $label));
            }
        }
    }

    /**
     * @return array{
     *     source_type: string,
     *     source_id: string,
     *     source_title: string,
     *     source_url_or_file: string,
     *     page_or_section: string,
     *     field_or_chunk_id: string,
     *     quote_or_value: string
     * }
     */
    public function citationArray(): array
    {
        return [
            'source_type' => 'guideline',
            'source_id' => $this->sourceTitle,
            'source_title' => $this->sourceTitle,
            'source_url_or_file' => $this->sourceUrlOrFile,
            'page_or_section' => $this->section,
            'field_or_chunk_id' => $this->chunkId,
            'quote_or_value' => EvidenceText::bounded($this->chunkText, 180),
        ];
    }

    public function toEvidenceBundleItem(): EvidenceBundleItem
    {
        return new EvidenceBundleItem(
            'guideline',
            sprintf('guideline:%s/%s', $this->sourceTitle, $this->chunkId),
            'unknown',
            sprintf('%s - %s', $this->sourceTitle, $this->section),
            EvidenceText::bounded($this->chunkText, EvidenceBundleItem::MAX_VALUE_LENGTH),
        );
    }
}
