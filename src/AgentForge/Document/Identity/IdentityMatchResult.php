<?php

/**
 * Result returned by deterministic document identity verification.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\AgentForge\Document\Identity;

use DomainException;

final readonly class IdentityMatchResult
{
    /**
     * @param list<array<string, mixed>> $extractedIdentifiers
     * @param array<string, string>      $matchedPatientFields
     */
    public function __construct(
        public IdentityStatus $status,
        public array $extractedIdentifiers,
        public array $matchedPatientFields,
        public ?string $mismatchReason,
        public bool $reviewRequired,
    ) {
        if ($mismatchReason !== null && trim($mismatchReason) === '') {
            throw new DomainException('Mismatch reason must be null or non-empty.');
        }
    }

    public function trustedForEvidence(): bool
    {
        return $this->status->trustedForEvidence();
    }
}
