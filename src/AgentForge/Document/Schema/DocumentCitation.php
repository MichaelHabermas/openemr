<?php

/**
 * Required provenance for an extracted document fact.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\AgentForge\Document\Schema;

final readonly class DocumentCitation
{
    public function __construct(
        public DocumentSourceType $sourceType,
        public string $sourceId,
        public string $pageOrSection,
        public string $fieldOrChunkId,
        public string $quoteOrValue,
        public ?BoundingBox $boundingBox = null,
    ) {
        foreach ([
            'source_id' => $sourceId,
            'page_or_section' => $pageOrSection,
            'field_or_chunk_id' => $fieldOrChunkId,
            'quote_or_value' => $quoteOrValue,
        ] as $field => $value) {
            if ($value === '') {
                throw new ExtractionSchemaException($field, 'Expected non-empty string.');
            }
        }
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data, string $path = 'citation', ?DocumentSourceType $expectedSourceType = null): self
    {
        SchemaReader::assertNoUnknownFields(
            $data,
            ['source_type', 'source_id', 'page_or_section', 'field_or_chunk_id', 'quote_or_value', 'bounding_box'],
            $path,
        );

        $sourceType = DocumentSourceType::tryFrom(SchemaReader::requiredString($data, 'source_type', $path));
        if ($sourceType === null) {
            throw new ExtractionSchemaException(SchemaReader::join($path, 'source_type'), 'Expected supported source type.');
        }
        if ($expectedSourceType !== null && $sourceType !== $expectedSourceType) {
            throw new ExtractionSchemaException(
                SchemaReader::join($path, 'source_type'),
                sprintf('Expected source type %s.', $expectedSourceType->value),
            );
        }

        return new self(
            $sourceType,
            SchemaReader::requiredString($data, 'source_id', $path),
            SchemaReader::requiredString($data, 'page_or_section', $path),
            SchemaReader::requiredString($data, 'field_or_chunk_id', $path),
            SchemaReader::requiredString($data, 'quote_or_value', $path),
            array_key_exists('bounding_box', $data) && $data['bounding_box'] !== null
                ? BoundingBox::fromArray(SchemaReader::requiredObject($data, 'bounding_box', $path), SchemaReader::join($path, 'bounding_box'))
                : null,
        );
    }
}
