<?php

/**
 * Isolated tests for clinical document cost/latency artifact normalization.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Tests\Isolated\AgentForge\Reporting;

use OpenEMR\AgentForge\Reporting\ClinicalDocumentCostLatencyArtifactNormalizer;
use PHPUnit\Framework\TestCase;

final class ClinicalDocumentCostLatencyArtifactNormalizerTest extends TestCase
{
    public function testNormalizesCostLatencyAndPlaceholderClinicalHandoffs(): void
    {
        $dir = $this->artifactDir();
        $clinicalRun = $dir . '/run.json';
        $clinicalSummary = $dir . '/summary.json';
        $tier2 = $dir . '/tier2.json';
        $smoke = $dir . '/smoke.json';

        $this->writeJson($clinicalRun, [
            'executed_at_utc' => '2026-05-06T23:07:14+00:00',
            'cases' => [
                ['answer_handoffs' => [['latency_ms' => 0], ['latency_ms' => 0]]],
            ],
        ]);
        $this->writeJson($clinicalSummary, [
            'executed_at_utc' => '2026-05-06T23:07:14+00:00',
            'verdict' => 'baseline_met',
            'case_count' => 59,
        ]);
        $this->writeJson($tier2, [
            'provider_model' => 'gpt-5.4-mini',
            'aggregate_input_tokens' => 5943,
            'aggregate_output_tokens' => 2476,
            'aggregate_estimated_cost_usd' => 0.01559925,
            'results' => [
                ['latency_ms' => 100, 'log_context' => ['stage_timings_ms' => ['draft' => 90, 'verify' => 10]]],
                ['latency_ms' => 300, 'log_context' => ['stage_timings_ms' => ['draft' => 100]]],
            ],
        ]);
        $this->writeJson($smoke, [
            'cases' => [
                ['latency_ms' => 200],
                ['latency_ms' => 400],
            ],
        ]);

        $run = (new ClinicalDocumentCostLatencyArtifactNormalizer())->normalize($clinicalRun, $clinicalSummary, $tier2, $smoke);

        $this->assertSame('baseline_met', $run->clinicalVerdict);
        $this->assertSame(59, $run->clinicalCaseCount);
        $this->assertTrue($run->clinicalLatencyPlaceholder());
        $this->assertSame(0.01559925, $run->tier2EstimatedCostUsd);
        $this->assertSame([100, 300], $run->tier2LatenciesMs);
        $this->assertSame([200, 400], $run->deployedSmokeLatenciesMs);
        $this->assertSame(['draft' => 190, 'verify' => 10], $run->stageTimingsMs);
    }

    private function artifactDir(): string
    {
        $dir = sys_get_temp_dir() . '/agentforge-report-test-' . bin2hex(random_bytes(4));
        $this->assertTrue(mkdir($dir, 0775, true));

        return $dir;
    }

    /** @param array<string, mixed> $data */
    private function writeJson(string $file, array $data): void
    {
        $encoded = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $this->assertNotFalse(file_put_contents($file, $encoded . "\n"));
    }
}
