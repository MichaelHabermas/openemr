<?php

/**
 * One cited patient identifier extracted from document content.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\AgentForge\Document\Identity;

use DomainException;
use OpenEMR\AgentForge\Document\Schema\Certainty;
use OpenEMR\AgentForge\Document\Schema\DocumentCitation;

final readonly class DocumentIdentityCandidate
{
    public function __construct(
        public DocumentIdentityCandidateKind $kind,
        public string $value,
        public string $fieldPath,
        public float $confidence,
        public Certainty $certainty,
        public DocumentCitation $citation,
    ) {
        if (trim($value) === '') {
            throw new DomainException('Identity candidate value must be present.');
        }
        if (trim($fieldPath) === '') {
            throw new DomainException('Identity candidate field path must be present.');
        }
        if ($confidence < 0.0 || $confidence > 1.0) {
            throw new DomainException('Identity candidate confidence must be between 0 and 1.');
        }
    }

    /** @return array<string, mixed> */
    public function toRedactedArray(): array
    {
        return [
            'kind' => $this->kind->value,
            'field_path' => $this->fieldPath,
            'confidence' => $this->confidence,
            'certainty' => $this->certainty->value,
            'citation' => [
                'source_type' => $this->citation->sourceType->value,
                'source_id' => $this->citation->sourceId,
                'page_or_section' => $this->citation->pageOrSection,
                'field_or_chunk_id' => $this->citation->fieldOrChunkId,
            ],
        ];
    }
}
