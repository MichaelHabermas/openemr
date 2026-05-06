<?php

/**
 * One structured finding extracted from an intake form.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\AgentForge\Document\Schema;

final readonly class IntakeFormFinding
{
    /**
     * @param Certainty $certainty Model-reported certainty from extraction JSON. Policy uses {@see CertaintyClassifier}
     *                             unless the model sets {@see Certainty::NeedsReview}.
     */
    public function __construct(
        public string $field,
        public string $value,
        public Certainty $certainty,
        public float $confidence,
        public DocumentCitation $citation,
    ) {
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data, string $path = 'findings[0]'): self
    {
        SchemaReader::assertNoUnknownFields(
            $data,
            ['field', 'value', 'certainty', 'confidence', 'citation'],
            $path,
        );

        $certainty = Certainty::tryFrom(SchemaReader::requiredString($data, 'certainty', $path));
        if ($certainty === null) {
            throw new ExtractionSchemaException(SchemaReader::join($path, 'certainty'), 'Expected supported certainty.');
        }

        return new self(
            SchemaReader::requiredString($data, 'field', $path),
            SchemaReader::requiredString($data, 'value', $path),
            $certainty,
            SchemaReader::requiredConfidence($data, 'confidence', $path),
            DocumentCitation::fromArray(SchemaReader::requiredObject($data, 'citation', $path), SchemaReader::join($path, 'citation')),
        );
    }
}
