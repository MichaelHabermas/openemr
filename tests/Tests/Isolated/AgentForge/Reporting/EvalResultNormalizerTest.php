<?php

/**
 * Isolated tests for AgentForge eval JSON normalization for reporting.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Tests\Isolated\AgentForge\Reporting;

use OpenEMR\AgentForge\Reporting\EvalResultNormalizer;
use PHPUnit\Framework\TestCase;

final class EvalResultNormalizerTest extends TestCase
{
    public function testFromTierZeroFixtureShape(): void
    {
        $json = [
            'fixture_version' => 'fv-test',
            'timestamp' => '2026-05-02T00:00:00+00:00',
            'code_version' => 'abc1234',
            'total' => 1,
            'passed' => 1,
            'failed' => 0,
            'safety_failure' => false,
            'results' => [
                [
                    'id' => 'demo_case_a',
                    'passed' => true,
                    'failure_reason' => '',
                    'status' => 'ok',
                    'log_context' => ['request_id' => 'eval-demo_case_a'],
                ],
            ],
        ];

        $n = (new EvalResultNormalizer())->fromDecodedJson($json);
        $this->assertSame('tier0_fixture', $n->tierKey);
        $this->assertSame(1, $n->passed);
        $this->assertSame(0, $n->failed);
        $this->assertSame(0, $n->skipped);
        $this->assertSame(1, $n->total);
        $this->assertFalse($n->safetyFailure);
        $this->assertSame('demo_case_a', $n->caseRows[0]->id);
    }

    public function testFromTierOneSqlEvidenceShape(): void
    {
        $json = [
            'tier' => 'seeded_sql_evidence',
            'fixture_version' => 'gt-v1',
            'timestamp' => '2026-05-02T01:00:00+00:00',
            'code_version' => 'deadbeef',
            'environment_label' => 'ci',
            'total' => 2,
            'passed' => 1,
            'failed' => 1,
            'results' => [
                [
                    'id' => 'sql_ok',
                    'patient_id' => 900001,
                    'description' => 'ok',
                    'passed' => true,
                    'failure_reason' => '',
                    'latency_ms' => 1,
                ],
                [
                    'id' => 'sql_bad',
                    'patient_id' => 900001,
                    'description' => 'bad',
                    'passed' => false,
                    'failure_reason' => 'missing citation',
                    'latency_ms' => 2,
                ],
            ],
        ];

        $n = (new EvalResultNormalizer())->fromDecodedJson($json);
        $this->assertSame('tier1_sql_evidence', $n->tierKey);
        $this->assertSame(2, $n->total);
        $this->assertSame(1, $n->passed);
        $this->assertSame(1, $n->failed);
        $this->assertSame('ci', $n->metaRows[0]['value']);
    }

    public function testFromTierTwoLiveModelShape(): void
    {
        $json = [
            'fixture_version' => 't2fv',
            'tier' => 'tier2-live-model',
            'timestamp' => '2026-05-02T02:00:00+00:00',
            'code_version' => 'cafebabe',
            'provider_mode' => 'openai',
            'provider_model' => 'gpt-4o-mini',
            'total' => 1,
            'passed' => 1,
            'failed' => 0,
            'safety_failure' => false,
            'aggregate_input_tokens' => 10,
            'aggregate_output_tokens' => 20,
            'aggregate_estimated_cost_usd' => 0.000012,
            'results' => [
                [
                    'id' => 'live_a1c',
                    'passed' => true,
                    'failure_reason' => '',
                    'status' => 'ok',
                    'log_context' => ['model' => 'gpt-4o-mini', 'input_tokens' => 10, 'output_tokens' => 20, 'estimated_cost' => 0.000012],
                ],
            ],
        ];

        $n = (new EvalResultNormalizer())->fromDecodedJson($json);
        $this->assertSame('tier2_live_model', $n->tierKey);
        $this->assertSame(1, $n->passed);
        $this->assertStringContainsString('openai', $n->metaRows[0]['value']);
    }

    public function testFromTierFourDeployedSmokeShape(): void
    {
        $json = [
            'tier' => 'deployed_smoke',
            'code_version' => '0000001',
            'deployed_url' => 'https://example.test/',
            'executed_at_utc' => '2026-05-02T03:00:00+00:00',
            'executor' => 'phpunit',
            'audit_log_assertions_enabled' => true,
            'summary' => [
                'total' => 3,
                'passed' => 1,
                'failed' => 1,
                'skipped' => 1,
                'aggregate_latency_ms' => 100,
            ],
            'cases' => [
                [
                    'id' => 'tier4_supported_a1c',
                    'verdict' => 'pass',
                    'failure_detail' => null,
                    'http_status' => 200,
                    'request_id' => 'r1',
                    'latency_ms' => 50,
                ],
                [
                    'id' => 'tier4_refusal_dosing',
                    'verdict' => 'fail',
                    'failure_detail' => 'expected refusal prefix',
                    'http_status' => 200,
                    'request_id' => 'r2',
                    'latency_ms' => 30,
                ],
                [
                    'id' => 'tier4_skipped_probe',
                    'verdict' => 'skipped',
                    'failure_detail' => null,
                    'http_status' => null,
                    'request_id' => null,
                    'latency_ms' => 20,
                ],
            ],
        ];

        $n = (new EvalResultNormalizer())->fromDecodedJson($json);
        $this->assertSame('tier4_deployed_smoke', $n->tierKey);
        $this->assertSame(1, $n->passed);
        $this->assertSame(1, $n->failed);
        $this->assertSame(1, $n->skipped);
        $this->assertSame(3, $n->total);
    }

    public function testUnknownJsonThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new EvalResultNormalizer())->fromDecodedJson(['foo' => 'bar']);
    }
}
