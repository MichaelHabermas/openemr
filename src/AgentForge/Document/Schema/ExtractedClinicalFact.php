<?php

/**
 * Shared cited fact contract for multi-format clinical document fixture coverage.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\AgentForge\Document\Schema;

final readonly class ExtractedClinicalFact
{
    public function __construct(
        public string $type,
        public string $fieldPath,
        public string $label,
        public string $value,
        public Certainty $certainty,
        public float $confidence,
        public DocumentCitation $citation,
    ) {
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data, string $path = 'facts[0]', ?DocumentSourceType $expectedSourceType = null): self
    {
        SchemaReader::assertNoUnknownFields(
            $data,
            ['type', 'field_path', 'label', 'value', 'certainty', 'confidence', 'citation'],
            $path,
        );

        $certainty = Certainty::tryFrom(SchemaReader::requiredString($data, 'certainty', $path));
        if ($certainty === null) {
            throw new ExtractionSchemaException(SchemaReader::join($path, 'certainty'), 'Expected supported certainty.');
        }

        return new self(
            SchemaReader::requiredString($data, 'type', $path),
            SchemaReader::requiredString($data, 'field_path', $path),
            SchemaReader::requiredString($data, 'label', $path),
            SchemaReader::requiredString($data, 'value', $path),
            $certainty,
            SchemaReader::requiredConfidence($data, 'confidence', $path),
            DocumentCitation::fromArray(SchemaReader::requiredObject($data, 'citation', $path), SchemaReader::join($path, 'citation'), $expectedSourceType),
        );
    }
}
