<?php

/**
 * Counts facts considered by AgentForge document promotion.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\AgentForge\Document\Promotion;

final readonly class PromotionSummary
{
    public function __construct(
        public int $promoted,
        public int $needsReview,
        public int $skipped,
    ) {
    }

    public static function empty(): self
    {
        return new self(0, 0, 0);
    }
}
