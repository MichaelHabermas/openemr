<?php

/**
 * Tier-agnostic eval run summary for human-readable reports.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\AgentForge\Reporting;

/**
 * @param list<array{label: string, value: string}> $metaRows
 * @param list<NormalizedEvalCaseRow>               $caseRows
 */
final readonly class NormalizedEvalRun
{
    /**
     * @param list<array{label: string, value: string}> $metaRows
     * @param list<NormalizedEvalCaseRow>               $caseRows
     */
    public function __construct(
        public string $tierKey,
        public string $title,
        public string $audienceSummary,
        public int $passed,
        public int $failed,
        public int $total,
        public int $skipped,
        public ?bool $safetyFailure,
        public string $timestamp,
        public string $codeVersion,
        public array $metaRows,
        public array $caseRows,
    ) {
        if ($this->passed < 0 || $this->failed < 0 || $this->skipped < 0 || $this->total < 0) {
            throw new \InvalidArgumentException('Eval counts must be non-negative.');
        }

        if ($this->passed + $this->failed + $this->skipped !== $this->total) {
            throw new \InvalidArgumentException('Eval total must equal passed plus failed plus skipped.');
        }
    }
}
