<?php

/**
 * GitHub-flavored Markdown summary of a normalized eval run.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\AgentForge\Reporting;

final class MarkdownEvalSummaryRenderer implements EvalSummaryRendererInterface
{
    public function render(NormalizedEvalRun $run): string
    {
        $lines = [];
        $lines[] = '## ' . $this->escapeMdHeadingText($run->title);
        $lines[] = '';
        $lines[] = $run->audienceSummary;
        $lines[] = '';

        $lines[] = '| Metric | Value |';
        $lines[] = '| --- | --- |';
        $lines[] = sprintf('| Result | **%d** passed, **%d** failed |', $run->passed, $run->failed);

        if ($run->skipped > 0) {
            $lines[] = sprintf('| Skipped | **%d** |', $run->skipped);
        }

        $lines[] = sprintf('| Total cases | **%d** |', $run->total);
        $lines[] = sprintf('| Timestamp (UTC) | %s |', $this->escapeTableCell($run->timestamp));
        $lines[] = sprintf('| Code version | `%s` |', $this->escapeTableCell($run->codeVersion));

        if ($run->safetyFailure !== null) {
            $lines[] = sprintf(
                '| Safety-critical failure | %s |',
                $run->safetyFailure ? '**yes** (investigate)' : 'no',
            );
        }

        $lines[] = '';

        if ($run->metaRows !== []) {
            $lines[] = '### Run context';
            $lines[] = '';
            $lines[] = '| Field | Value |';
            $lines[] = '| --- | --- |';
            foreach ($run->metaRows as $row) {
                $lines[] = sprintf(
                    '| %s | %s |',
                    $this->escapeTableCell($row['label']),
                    $this->escapeTableCell($row['value']),
                );
            }

            $lines[] = '';
        }

        if ($run->caseRows !== []) {
            $lines[] = '### Cases';
            $lines[] = '';
            $lines[] = '| Case id | Outcome | Detail |';
            $lines[] = '| --- | --- | --- |';
            foreach ($run->caseRows as $row) {
                $outcome = $row->passed ? 'pass' : 'fail';
                $lines[] = sprintf(
                    '| `%s` | %s | %s |',
                    $this->escapeTableCell($row->id),
                    $outcome,
                    $this->escapeTableCell($row->detail),
                );
            }

            $lines[] = '';
        }

        $lines[] = '### Full machine-readable output';
        $lines[] = '';
        $lines[] = 'Download the JSON artifact from this workflow run (or read the path printed by the eval script locally) for complete fields, timings, and log context.';

        return implode("\n", $lines) . "\n";
    }

    private function escapeMdHeadingText(string $text): string
    {
        return str_replace("\n", ' ', $text);
    }

    private function escapeTableCell(string $text): string
    {
        $t = str_replace("\n", ' ', $text);
        $t = str_replace('|', '\\|', $t);

        return $t;
    }
}
