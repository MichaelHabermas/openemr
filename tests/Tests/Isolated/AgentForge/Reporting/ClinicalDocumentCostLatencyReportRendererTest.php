<?php

/**
 * Isolated tests for clinical document cost/latency Markdown rendering.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Tests\Isolated\AgentForge\Reporting;

use OpenEMR\AgentForge\Reporting\ClinicalDocumentCostLatencyReportRenderer;
use OpenEMR\AgentForge\Reporting\ClinicalDocumentCostLatencyRun;
use PHPUnit\Framework\TestCase;

final class ClinicalDocumentCostLatencyReportRendererTest extends TestCase
{
    public function testRendersHonestCostLatencyReportFromNormalizedRun(): void
    {
        $markdown = (new ClinicalDocumentCostLatencyReportRenderer())->render(new ClinicalDocumentCostLatencyRun(
            clinicalExecutedAt: '2026-05-06T23:07:14+00:00',
            clinicalVerdict: 'baseline_met',
            clinicalCaseCount: 59,
            clinicalSummary: [],
            clinicalHandoffLatenciesMs: [0, 0, 0],
            tier2EstimatedCostUsd: 0.01559925,
            tier2InputTokens: 5943,
            tier2OutputTokens: 2476,
            tier2ProviderModel: 'gpt-5.4-mini',
            tier2LatenciesMs: [100, 300, 900],
            deployedSmokeLatenciesMs: [200, 400, 1000],
            stageTimingsMs: ['draft' => 190, 'verify' => 10],
            evidencePaths: ['run.json', 'summary.json'],
        ));

        $this->assertStringContainsString('placeholder 0 ms', $markdown);
        $this->assertStringContainsString('$0.015599', $markdown);
        $this->assertStringContainsString('gpt-5.4-mini', $markdown);
        $this->assertStringContainsString('Clinical handoff p95', $markdown);
        $this->assertStringContainsString('Tier 2 live p95', $markdown);
        $this->assertStringContainsString('Stage-timing drivers', $markdown);
        $this->assertStringContainsString('`draft` - `190 ms` aggregate', $markdown);
        $this->assertStringContainsString('render-clinical-document-cost-latency.php', $markdown);
    }

    public function testRendersUnknownInsteadOfZeroForMissingCost(): void
    {
        $markdown = (new ClinicalDocumentCostLatencyReportRenderer())->render(new ClinicalDocumentCostLatencyRun(
            clinicalExecutedAt: '2026-05-06T23:07:14+00:00',
            clinicalVerdict: 'baseline_met',
            clinicalCaseCount: 59,
            clinicalSummary: [],
            clinicalHandoffLatenciesMs: [25, 50, 75],
            tier2EstimatedCostUsd: null,
            tier2InputTokens: null,
            tier2OutputTokens: null,
            tier2ProviderModel: null,
            tier2LatenciesMs: [],
            deployedSmokeLatenciesMs: [],
            stageTimingsMs: [],
            evidencePaths: ['run.json'],
        ));

        $this->assertStringContainsString('unknown/not recorded', $markdown);
        $this->assertStringContainsString('Fallback drivers', $markdown);
    }
}
