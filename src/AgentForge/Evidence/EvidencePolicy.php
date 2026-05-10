<?php

/**
 * Evidence collection policy enum controlling speed vs completeness tradeoffs.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\AgentForge\Evidence;

enum EvidencePolicy: string
{
    /**
     * Prefetch all evidence sections, accepting deadline risk for completeness.
     * Best for: Pre-visit summaries where physician needs full context.
     */
    case Eager = 'eager';

    /**
     * Collect only explicitly planned sections.
     * Best for: Specific questions where target is clear.
     */
    case Lazy = 'lazy';

    /**
     * Re-extract from source documents if chart data is missing.
     * Best for: Questions about documents uploaded but not yet fully processed.
     */
    case OnDemand = 'on_demand';

    /**
     * Get the default policy for general queries.
     */
    public static function default(): self
    {
        return self::Lazy;
    }

    /**
     * Check if this policy should prefetch unplanned sections.
     */
    public function shouldPrefetch(): bool
    {
        return $this === self::Eager;
    }

    /**
     * Check if this policy should re-extract from documents.
     */
    public function shouldReextract(): bool
    {
        return $this === self::OnDemand;
    }
}
