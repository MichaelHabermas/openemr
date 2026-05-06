<?php

/**
 * Extracted document identity evidence presented to the verifier.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\AgentForge\Document\Identity;

use OpenEMR\AgentForge\Document\DocumentId;
use OpenEMR\AgentForge\Document\DocumentType;

final readonly class DocumentIdentityEvidence
{
    /** @param list<DocumentIdentityCandidate> $candidates */
    public function __construct(
        public DocumentId $documentId,
        public DocumentType $documentType,
        public ?string $documentName,
        public array $candidates,
    ) {
    }

    /** @return list<array<string, mixed>> */
    public function redactedCandidateSummaries(): array
    {
        return array_map(
            static fn (DocumentIdentityCandidate $candidate): array => $candidate->toRedactedArray(),
            $this->candidates,
        );
    }
}
