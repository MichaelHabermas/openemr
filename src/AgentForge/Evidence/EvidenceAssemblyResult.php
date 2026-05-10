<?php

/**
 * Immutable result of evidence assembly with timing and coverage information.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\AgentForge\Evidence;

final readonly class EvidenceAssemblyResult
{
    /**
     * @param EvidenceBundle $bundle The collected evidence bundle
     * @param EvidenceCoverageReport $coverage Coverage report (found/missing/failed)
     * @param array<string, int> $timingMs Timing per section/tool in milliseconds
     * @param bool $deadlineExceeded Whether the deadline was exceeded
     * @param list<string> $toolsCalled Names of tools that were invoked
     * @param ?array<string, mixed> $mergeTelemetry Optional merge telemetry for guidelines
     */
    public function __construct(
        public EvidenceBundle $bundle,
        public EvidenceCoverageReport $coverage,
        public array $timingMs = [],
        public bool $deadlineExceeded = false,
        public array $toolsCalled = [],
        public ?array $mergeTelemetry = null,
    ) {
    }

    /**
     * Convert to EvidenceRun for backward compatibility.
     */
    public function toEvidenceRun(): EvidenceRun
    {
        return new EvidenceRun(
            bundle: $this->bundle,
            results: [], // Results populated by caller if needed
            toolsCalled: $this->toolsCalled,
            skippedSections: $this->coverage->missingSections,
            mergeTelemetry: $this->mergeTelemetry,
        );
    }
}
