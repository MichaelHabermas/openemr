<?php

/**
 * Coverage report for evidence assembly showing what was/wasn't found.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\AgentForge\Evidence;

final readonly class EvidenceCoverageReport
{
    /**
     * @param list<string> $foundSections Sections with evidence found
     * @param list<string> $missingSections Sections with no evidence (but not failed)
     * @param list<string> $failedSections Sections where tool failed
     * @param int $totalSections Total number of sections planned
     * @param ?string $deadlineReason Why deadline was exceeded (if applicable)
     */
    public function __construct(
        public array $foundSections = [],
        public array $missingSections = [],
        public array $failedSections = [],
        public int $totalSections = 0,
        public ?string $deadlineReason = null,
    ) {
    }

    /**
     * Calculate coverage percentage (0-100).
     */
    public function coveragePercent(): int
    {
        if ($this->totalSections === 0) {
            return 0;
        }

        return (int) round((count($this->foundSections) / $this->totalSections) * 100);
    }

    /**
     * Check if all planned sections were covered.
     */
    public function isComplete(): bool
    {
        return $this->missingSections === [] && $this->failedSections === [];
    }

    /**
     * Create from EvidenceBundle sections.
     */
    public static function fromBundle(EvidenceBundle $bundle, int $totalPlanned): self
    {
        return new self(
            foundSections: array_map(
                static fn (EvidenceBundleItem $item) => $item->section,
                $bundle->items,
            ),
            missingSections: $bundle->missingSections,
            failedSections: $bundle->failedSections,
            totalSections: $totalPlanned,
        );
    }
}
