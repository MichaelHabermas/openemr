<?php

/**
 * Identity verification states for AgentForge clinical documents.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\AgentForge\Document\Identity;

use DomainException;

enum IdentityStatus: string
{
    case Unchecked = 'identity_unchecked';
    case Verified = 'identity_verified';
    case AmbiguousNeedsReview = 'identity_ambiguous_needs_review';
    case MismatchQuarantined = 'identity_mismatch_quarantined';
    case ReviewApproved = 'identity_review_approved';
    case ReviewRejected = 'identity_review_rejected';

    public static function fromStringOrThrow(string $value): self
    {
        return self::tryFrom($value) ?? throw new DomainException("Unsupported identity status: {$value}");
    }

    public function trustedForEvidence(): bool
    {
        return $this === self::Verified || $this === self::ReviewApproved;
    }
}
