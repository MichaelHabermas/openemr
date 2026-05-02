<?php

/**
 * Isolated regression tests for the AgentForge cost-analysis deliverable.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Tests\Isolated\AgentForge;

use PHPUnit\Framework\TestCase;

final class CostAnalysisDocumentTest extends TestCase
{
    public function testCostAnalysisCoversRequiredUserTiersAndScaleDrivers(): void
    {
        $document = $this->costAnalysisDocument();

        foreach (['100 users', '1,000 users', '10,000 users', '100,000 users'] as $tier) {
            $this->assertStringContainsString($tier, $document);
        }

        foreach (
            [
                'Assumptions Table',
                'Low / Base / High Usage Scenarios',
                'User-Tier Monthly Projection',
                'Architecture Changes By Tier',
                'Non-Token Cost Categories',
                'Known Unknowns And Measurement Plan',
            ] as $requiredSection
        ) {
            $this->assertStringContainsString('## ' . $requiredSection, $document);
        }

        foreach (
            [
                'requests per clinician per workday',
                'work days per month',
                'question mix',
                'average chart evidence size',
                'model input/output tokens',
                'retry rate',
                'cache hit rate',
                'live-provider pricing source',
                'hosting',
                'storage',
                'monitoring',
                'backup',
                'support/on-call',
                'compliance/admin',
            ] as $scaleDriver
        ) {
            $this->assertStringContainsString($scaleDriver, $document);
        }
    }

    public function testMeasuredA1cRequestIsBaselineNotProductionForecast(): void
    {
        $document = $this->costAnalysisDocument();

        $this->assertStringContainsString('single measured request is a baseline, not a production forecast', $document);
        $this->assertStringContainsString('836 input tokens', $document);
        $this->assertStringContainsString('173 output tokens', $document);
        $this->assertStringContainsString('$0.0002292', $document);
        $this->assertStringNotContainsString('## Baseline-Only Projection', $document);
        $this->assertStringNotContainsString('Required User-Tier Rewrite Structure', $document);
    }

    public function testBaseScenarioModelSpendMatchesDocumentedFormula(): void
    {
        $document = $this->costAnalysisDocument();
        $baseCostPerRequest = ((1800 * 0.85) / 1000000 * 0.15 + (300 / 1000000 * 0.60)) * 1.05;

        $expected = [
            '100 users' => 100 * 8 * 21 * $baseCostPerRequest,
            '1,000 users' => 1000 * 8 * 21 * $baseCostPerRequest,
            '10,000 users' => 10000 * 8 * 21 * $baseCostPerRequest,
            '100,000 users' => 100000 * 8 * 21 * $baseCostPerRequest,
        ];

        foreach ($expected as $tier => $monthlyCost) {
            $this->assertStringContainsString($tier, $document);
            $this->assertStringContainsString('$' . number_format($monthlyCost, 2), $document);
        }
    }

    private function costAnalysisDocument(): string
    {
        $path = dirname(__DIR__, 4) . '/agent-forge/docs/operations/COST-ANALYSIS.md';
        $document = file_get_contents($path);

        $this->assertIsString($document);

        return $document;
    }
}
