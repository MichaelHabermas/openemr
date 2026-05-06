<?php

/**
 * Normalized citation bounding box.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\AgentForge\Document\Schema;

final readonly class BoundingBox
{
    public function __construct(
        public float $x,
        public float $y,
        public float $width,
        public float $height,
    ) {
        foreach (['x' => $x, 'y' => $y, 'width' => $width, 'height' => $height] as $field => $value) {
            if ($value < 0.0 || $value > 1.0) {
                throw new ExtractionSchemaException($field, 'Expected normalized number between 0 and 1.');
            }
        }

        if ($width <= 0.0) {
            throw new ExtractionSchemaException('width', 'Expected positive width.');
        }
        if ($height <= 0.0) {
            throw new ExtractionSchemaException('height', 'Expected positive height.');
        }
        if ($x + $width > 1.0) {
            throw new ExtractionSchemaException('width', 'Expected x plus width to be no greater than 1.');
        }
        if ($y + $height > 1.0) {
            throw new ExtractionSchemaException('height', 'Expected y plus height to be no greater than 1.');
        }
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data, string $path = 'bounding_box'): self
    {
        SchemaReader::assertNoUnknownFields($data, ['x', 'y', 'width', 'height'], $path);

        try {
            return new self(
                SchemaReader::requiredFloat($data, 'x', $path),
                SchemaReader::requiredFloat($data, 'y', $path),
                SchemaReader::requiredFloat($data, 'width', $path),
                SchemaReader::requiredFloat($data, 'height', $path),
            );
        } catch (ExtractionSchemaException $e) {
            if (str_contains($e->fieldPath, '.')) {
                throw $e;
            }

            throw new ExtractionSchemaException(
                SchemaReader::join($path, $e->fieldPath),
                substr($e->getMessage(), strlen($e->fieldPath) + 2),
            );
        }
    }
}
