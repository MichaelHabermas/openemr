<?php

/**
 * PHI-safe summary of normalized document content.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\AgentForge\Document\Content;

final readonly class DocumentContentTelemetry
{
    /** @param list<string> $warningCodes */
    public function __construct(
        public string $normalizer,
        public string $sourceMimeType,
        public int $sourceByteCount,
        public int $renderedPageCount,
        public int $textSectionCount,
        public int $tableCount,
        public int $messageSegmentCount,
        public array $warningCodes,
        public int $normalizationElapsedMs,
    ) {
    }

    /** @return array<string, mixed> */
    public function toLogContext(): array
    {
        return [
            'normalizer' => $this->normalizer,
            'source_mime_type' => $this->sourceMimeType,
            'source_byte_count' => $this->sourceByteCount,
            'rendered_page_count' => $this->renderedPageCount,
            'text_section_count' => $this->textSectionCount,
            'table_count' => $this->tableCount,
            'message_segment_count' => $this->messageSegmentCount,
            'warning_codes' => $this->warningCodes,
            'normalization_elapsed_ms' => $this->normalizationElapsedMs,
        ];
    }
}
