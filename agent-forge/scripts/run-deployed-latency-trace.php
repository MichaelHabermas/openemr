#!/usr/bin/env php
<?php

/**
 * Capture deployed AgentForge latency traces for A1c and visit briefing.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/vendor/autoload.php';
require_once __DIR__ . '/lib/deployed-smoke-runner.php';
require_once __DIR__ . '/lib/script-runtime.php';

exit(agentforge_deployed_latency_trace_main());

function agentforge_deployed_latency_trace_main(): int
{
    $config = agentforge_deployed_smoke_config();
    $iterations = agentforge_scripts_env_int('AGENTFORGE_LATENCY_TRACE_ITERATIONS', 20);
    if ($iterations <= 0) {
        fwrite(STDERR, "AGENTFORGE_LATENCY_TRACE_ITERATIONS must be positive.\n");

        return 2;
    }
    if ($config['username'] === '' || $config['password'] === '') {
        fwrite(STDERR, "Latency trace requires AGENTFORGE_SMOKE_USER and AGENTFORGE_SMOKE_PASSWORD.\n");

        return 2;
    }
    if (!$config['skip_audit_log'] && $config['ssh_host'] === null) {
        fwrite(STDERR, "Latency trace requires AGENTFORGE_VM_SSH_HOST unless AGENTFORGE_SMOKE_SKIP_AUDIT_LOG=1.\n");

        return 2;
    }

    $startedAt = new DateTimeImmutable('now', new DateTimeZone('UTC'));
    $questions = [
        'a1c' => 'Show me the recent A1c trend.',
        'visit_briefing' => 'Give me a visit briefing.',
    ];
    $traces = [];

    foreach ($questions as $label => $question) {
        for ($i = 1; $i <= $iterations; $i++) {
            $traces[] = agentforge_deployed_latency_trace_one($config, $label, $question, $i);
        }
    }

    $summary = [
        'executed_at_utc' => $startedAt->format(DateTimeInterface::ATOM),
        'deployed_url' => $config['base_url'],
        'iterations_per_question' => $iterations,
        'pass_budget_ms_p95' => 10000,
        'stats' => agentforge_deployed_latency_stats($traces),
        'traces' => $traces,
    ];

    if (!agentforge_scripts_ensure_directory($config['results_dir'], 'results directory')) {
        return 2;
    }

    $jsonPath = sprintf('%s/deployed-latency-trace-%s.json', rtrim($config['results_dir'], '/'), $startedAt->format('Ymd-His'));
    file_put_contents($jsonPath, json_encode($summary, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES) . "\n");

    $markdownPath = $config['repo_root'] . '/agent-forge/docs/operations/LATENCY-RESULTS.md';
    file_put_contents($markdownPath, agentforge_deployed_latency_markdown($summary, $jsonPath));

    printf("Latency trace written: %s\nSummary written: %s\n", $jsonPath, $markdownPath);

    return 0;
}

/**
 * @param array<string, mixed> $config
 * @return array<string, mixed>
 */
function agentforge_deployed_latency_trace_one(array $config, string $label, string $question, int $iteration): array
{
    $cookieJar = agentforge_deployed_smoke_temp_cookie_jar(sprintf('latency-%s-%d', $label, $iteration));
    $trace = [
        'label' => $label,
        'iteration' => $iteration,
        'request_id' => null,
        'http_status' => null,
        'status' => null,
        'latency_ms' => null,
        'stage_timings_ms' => [],
        'model' => null,
        'verifier_result' => null,
        'failure_reason' => null,
        'passed' => false,
    ];

    try {
        if (!agentforge_deployed_smoke_login($config['base_url'], $config['username'], $config['password'], $cookieJar, $config['timeout_s'])) {
            $trace['failure_reason'] = 'login_failed';
            return $trace;
        }
        if (!agentforge_deployed_smoke_set_pid($config['base_url'], $config['primary_pid'], $cookieJar, $config['timeout_s'])) {
            $trace['failure_reason'] = 'set_pid_failed';
            return $trace;
        }
        $csrf = agentforge_deployed_smoke_fetch_csrf_token($config['base_url'], $config['primary_pid'], $cookieJar, $config['timeout_s']);
        if ($csrf === null) {
            $trace['failure_reason'] = 'csrf_failed';
            return $trace;
        }
        $post = agentforge_deployed_smoke_post_question(
            $config['base_url'],
            $config['primary_pid'],
            $question,
            $csrf,
            null,
            $cookieJar,
            $config['timeout_s'],
        );
        $trace['request_id'] = $post['request_id'];
        $trace['http_status'] = $post['http_status'];
        $trace['status'] = $post['body']['status'] ?? null;
        $trace['latency_ms'] = $post['latency_ms'];

        if (!$config['skip_audit_log'] && is_string($post['request_id'])) {
            $audit = agentforge_deployed_smoke_grep_audit_log(
                (string) $config['ssh_host'],
                $config['audit_log_path'],
                $post['request_id'],
            );
            $fields = $audit['fields'];
            $trace['stage_timings_ms'] = is_array($fields['stage_timings_ms'] ?? null) ? $fields['stage_timings_ms'] : [];
            $trace['model'] = $fields['model'] ?? null;
            $trace['verifier_result'] = $fields['verifier_result'] ?? null;
            $trace['failure_reason'] = $fields['failure_reason'] ?? null;
        }
        $trace['passed'] = $trace['http_status'] === 200 && $trace['status'] === 'ok';

        return $trace;
    } finally {
        @unlink($cookieJar);
    }
}

/**
 * @param list<array<string, mixed>> $traces
 * @return array<string, mixed>
 */
function agentforge_deployed_latency_stats(array $traces): array
{
    $byLabel = [];
    foreach ($traces as $trace) {
        $label = is_string($trace['label'] ?? null) ? $trace['label'] : 'unknown';
        $byLabel[$label][] = $trace;
    }

    $stats = [];
    foreach ($byLabel as $label => $rows) {
        $latencies = [];
        foreach ($rows as $row) {
            if (is_int($row['latency_ms'] ?? null)) {
                $latencies[] = $row['latency_ms'];
            }
        }
        sort($latencies);
        $stats[$label] = [
            'count' => count($rows),
            'passed' => count(array_filter($rows, static fn (array $r): bool => ($r['passed'] ?? false) === true)),
            'p50_ms' => agentforge_deployed_latency_percentile($latencies, 50),
            'p95_ms' => agentforge_deployed_latency_percentile($latencies, 95),
            'max_ms' => $latencies === [] ? null : max($latencies),
        ];
    }

    return $stats;
}

/** @param list<int> $sorted */
function agentforge_deployed_latency_percentile(array $sorted, int $percentile): ?int
{
    if ($sorted === []) {
        return null;
    }
    $index = (int) ceil(($percentile / 100) * count($sorted)) - 1;

    return $sorted[max(0, min($index, count($sorted) - 1))];
}

/** @param array<string, mixed> $summary */
function agentforge_deployed_latency_markdown(array $summary, string $jsonPath): string
{
    $lines = [
        '# AgentForge Deployed Latency Results',
        '',
        sprintf('Generated: `%s`', $summary['executed_at_utc']),
        sprintf('Raw trace JSON: `%s`', $jsonPath),
        sprintf('Pass budget: p95 under `%d ms` for each traced question.', $summary['pass_budget_ms_p95']),
        '',
        '| Question | Count | Passed | p50 ms | p95 ms | Max ms |',
        '| --- | ---: | ---: | ---: | ---: | ---: |',
    ];
    foreach ($summary['stats'] as $label => $stats) {
        $lines[] = sprintf(
            '| %s | %d | %d | %s | %s | %s |',
            $label,
            $stats['count'],
            $stats['passed'],
            $stats['p50_ms'] === null ? 'n/a' : (string) $stats['p50_ms'],
            $stats['p95_ms'] === null ? 'n/a' : (string) $stats['p95_ms'],
            $stats['max_ms'] === null ? 'n/a' : (string) $stats['max_ms'],
        );
    }

    return implode("\n", $lines) . "\n";
}
