<?php

/**
 * Two-part prompt user message: stable cacheable evidence prefix and a delta question.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\AgentForge\ResponseGeneration;

final readonly class PromptParts
{
    public function __construct(
        public string $stableEvidence,
        public string $deltaQuestion,
    ) {
    }
}
