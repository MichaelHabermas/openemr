<?php

/**
 * Immutable value object pairing a draft response with its verification result.
 *
 * Separates the drafting phase from the verification phase while keeping
 * them together as a unit for downstream processing.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\AgentForge\ResponseGeneration;

use OpenEMR\AgentForge\Verification\VerificationResult;

final readonly class DraftVerificationPair
{
    public function __construct(
        public DraftResponse $draft,
        public VerificationResult $result,
    ) {
    }

    /**
     * Check if the draft passed verification.
     */
    public function isVerified(): bool
    {
        return $this->result->verified;
    }

    /**
     * Get the verified claims that passed fact-checking.
     *
     * @return list<DraftClaim>
     */
    public function verifiedClaims(): array
    {
        $verified = [];
        foreach ($this->draft->claims as $claim) {
            if ($this->result->verdictFor($claim)) {
                $verified[] = $claim;
            }
        }

        return $verified;
    }

    /**
     * Get the unverified claims that failed fact-checking.
     *
     * @return list<DraftClaim>
     */
    public function unverifiedClaims(): array
    {
        $unverified = [];
        foreach ($this->draft->claims as $claim) {
            if (!$this->result->verdictFor($claim)) {
                $unverified[] = $claim;
            }
        }

        return $unverified;
    }
}
