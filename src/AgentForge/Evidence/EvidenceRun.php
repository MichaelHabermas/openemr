<?php

/**
 * Result of bounded AgentForge evidence collection.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\AgentForge\Evidence;

final readonly class EvidenceRun
{
    /**
     * @param list<EvidenceResult> $results
     * @param list<string> $toolsCalled
     * @param list<string> $skippedSections
     * @param ?array<string, mixed> $mergeTelemetry
     */
    public function __construct(
        public EvidenceBundle $bundle,
        public array $results,
        public array $toolsCalled,
        public array $skippedSections = [],
        public ?array $mergeTelemetry = null,
    ) {
    }
}
