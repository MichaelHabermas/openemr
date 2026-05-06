<?php

/**
 * Canonical outcomes for AgentForge clinical document promotion attempts.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\AgentForge\Document\Promotion;

enum PromotionOutcome: string
{
    case Promoted = 'promoted';
    case AlreadyExists = 'already_exists';
    case DuplicateSkipped = 'duplicate_skipped';
    case ConflictNeedsReview = 'conflict_needs_review';
    case NotPromotable = 'not_promotable';
    case NeedsReview = 'needs_review';
    case Rejected = 'rejected';
    case PromotionFailed = 'promotion_failed';
    case Retracted = 'retracted';

    public function reviewStatus(): string
    {
        return match ($this) {
            self::NeedsReview,
            self::ConflictNeedsReview,
            self::NotPromotable,
            self::PromotionFailed,
            self::Rejected,
            self::Retracted => 'needs_review',
            self::Promoted,
            self::AlreadyExists,
            self::DuplicateSkipped => 'auto_accepted',
        };
    }
}
