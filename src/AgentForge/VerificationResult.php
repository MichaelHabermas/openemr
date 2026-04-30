<?php

/**
 * Result of deterministic draft verification.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\AgentForge;

final readonly class VerificationResult
{
    /**
     * @param list<string> $verifiedSentenceIds
     * @param list<string> $citations
     * @param list<string> $missingOrUncheckedSections
     * @param list<string> $refusalsOrWarnings
     */
    public function __construct(
        public bool $passed,
        public array $verifiedSentenceIds,
        public array $citations,
        public array $missingOrUncheckedSections,
        public array $refusalsOrWarnings,
    ) {
    }
}
