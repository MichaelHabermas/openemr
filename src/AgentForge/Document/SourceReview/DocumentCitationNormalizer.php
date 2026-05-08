<?php

/**
 * Shared normalizer for persisted AgentForge document citations.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\AgentForge\Document\SourceReview;

use OpenEMR\AgentForge\Document\Schema\BoundingBox;
use OpenEMR\AgentForge\Document\Schema\ExtractionSchemaException;

final class DocumentCitationNormalizer
{
    /**
     * @param array<string, mixed> $citation
     * @param array<string, mixed> $structured
     */
    public function normalize(array $citation, array $structured = []): NormalizedDocumentCitation
    {
        $pageOrSection = $this->string($citation, 'page_or_section') ?: 'unknown page';
        $pageNumber = $this->pageNumber($pageOrSection);
        if ($pageNumber !== null) {
            $pageOrSection = 'page ' . $pageNumber;
        }

        $field = $this->string($citation, 'field_or_chunk_id') ?: $this->string($structured, 'field_path');
        if ($field === '') {
            $field = 'unknown field';
        }

        return new NormalizedDocumentCitation(
            sourceType: $this->string($citation, 'source_type') ?: 'document',
            sourceId: $this->string($citation, 'source_id'),
            pageOrSection: $pageOrSection,
            pageNumber: $pageNumber,
            fieldOrChunkId: $field,
            quoteOrValue: $this->string($citation, 'quote_or_value'),
            boundingBox: $this->boundingBox($citation['bounding_box'] ?? $structured['bounding_box'] ?? null),
        );
    }

    /** @return array{x: float, y: float, width: float, height: float}|null */
    public function boundingBox(mixed $value): ?array
    {
        if (!is_array($value)) {
            return null;
        }

        try {
            $box = BoundingBox::fromArray($this->stringKeyed($value));
        } catch (ExtractionSchemaException) {
            return null;
        }

        return [
            'x' => $box->x,
            'y' => $box->y,
            'width' => $box->width,
            'height' => $box->height,
        ];
    }

    public function pageNumber(string $pageOrSection): ?int
    {
        if (preg_match('/\bpage\s*(\d+)\b/i', $pageOrSection, $matches) === 1) {
            return max(1, (int) $matches[1]);
        }

        if (preg_match('/^\s*(\d+)\s*$/', $pageOrSection, $matches) === 1) {
            return max(1, (int) $matches[1]);
        }

        return null;
    }

    /** @param array<string, mixed> $row */
    private function string(array $row, string $key): string
    {
        $value = $row[$key] ?? '';

        return is_scalar($value) ? trim((string) $value) : '';
    }

    /**
     * @param array<mixed> $value
     * @return array<string, mixed>
     */
    private function stringKeyed(array $value): array
    {
        $out = [];
        foreach ($value as $key => $item) {
            if (is_string($key)) {
                $out[$key] = $item;
            }
        }

        return $out;
    }
}
