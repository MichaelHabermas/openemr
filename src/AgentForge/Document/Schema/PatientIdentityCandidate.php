<?php

/**
 * One cited patient identifier emitted by strict document extraction.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\AgentForge\Document\Schema;

use OpenEMR\AgentForge\Document\Identity\DocumentIdentityCandidateKind;

final readonly class PatientIdentityCandidate
{
    public function __construct(
        public DocumentIdentityCandidateKind $kind,
        public string $value,
        public string $fieldPath,
        public Certainty $certainty,
        public float $confidence,
        public DocumentCitation $citation,
    ) {
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data, string $path = 'patient_identity[0]'): self
    {
        SchemaReader::assertNoUnknownFields(
            $data,
            ['kind', 'value', 'field_path', 'certainty', 'confidence', 'citation'],
            $path,
        );

        $kind = DocumentIdentityCandidateKind::tryFrom(SchemaReader::requiredString($data, 'kind', $path));
        if ($kind === null) {
            throw new ExtractionSchemaException(SchemaReader::join($path, 'kind'), 'Expected supported identity candidate kind.');
        }

        $certainty = Certainty::tryFrom(SchemaReader::requiredString($data, 'certainty', $path));
        if ($certainty === null) {
            throw new ExtractionSchemaException(SchemaReader::join($path, 'certainty'), 'Expected supported certainty.');
        }

        return new self(
            $kind,
            SchemaReader::requiredString($data, 'value', $path),
            SchemaReader::requiredString($data, 'field_path', $path),
            $certainty,
            SchemaReader::requiredConfidence($data, 'confidence', $path),
            DocumentCitation::fromArray(SchemaReader::requiredObject($data, 'citation', $path), SchemaReader::join($path, 'citation')),
        );
    }
}
