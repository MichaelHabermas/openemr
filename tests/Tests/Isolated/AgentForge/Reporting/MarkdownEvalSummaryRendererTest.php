<?php

/**
 * Isolated tests for Markdown eval summary rendering.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Tests\Isolated\AgentForge\Reporting;

use OpenEMR\AgentForge\Reporting\EvalResultNormalizer;
use OpenEMR\AgentForge\Reporting\MarkdownEvalSummaryRenderer;
use OpenEMR\AgentForge\Reporting\NormalizedEvalCaseRow;
use OpenEMR\AgentForge\Reporting\NormalizedEvalRun;
use PHPUnit\Framework\TestCase;

final class MarkdownEvalSummaryRendererTest extends TestCase
{
    public function testRenderContainsTierTitleCountsAndCaseId(): void
    {
        $json = [
            'fixture_version' => 'fv-md',
            'timestamp' => '2026-05-02T04:00:00+00:00',
            'code_version' => '1111111',
            'total' => 1,
            'passed' => 1,
            'failed' => 0,
            'safety_failure' => false,
            'results' => [
                [
                    'id' => 'markdown_probe_case',
                    'passed' => true,
                    'failure_reason' => '',
                    'status' => 'ok',
                    'log_context' => [],
                ],
            ],
        ];

        $run = (new EvalResultNormalizer())->fromDecodedJson($json);
        $md = (new MarkdownEvalSummaryRenderer())->render($run);

        $this->assertStringContainsString('Tier 0', $md);
        $this->assertStringContainsString('**1** passed', $md);
        $this->assertStringContainsString('markdown_probe_case', $md);
        $this->assertStringContainsString('Full machine-readable output', $md);
    }

    public function testTableCellEscapesPipe(): void
    {
        $run = new NormalizedEvalRun(
            tierKey: 'tier0_fixture',
            title: 'T',
            audienceSummary: 'S',
            passed: 0,
            failed: 1,
            total: 1,
            skipped: 0,
            safetyFailure: null,
            timestamp: 't',
            codeVersion: 'c',
            metaRows: [],
            caseRows: [
                new NormalizedEvalCaseRow('x', false, 'a|b'),
            ],
        );

        $md = (new MarkdownEvalSummaryRenderer())->render($run);
        $this->assertStringContainsString('a\\|b', $md);
    }
}
