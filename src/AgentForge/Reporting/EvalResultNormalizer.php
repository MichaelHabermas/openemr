<?php

/**
 * Maps AgentForge eval JSON (Tier 0/1/2/4) into NormalizedEvalRun for reporting.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\AgentForge\Reporting;

final class EvalResultNormalizer
{
    /**
     * @param array<string, mixed> $json
     */
    public function fromDecodedJson(array $json): NormalizedEvalRun
    {
        $tier = isset($json['tier']) && is_string($json['tier']) ? $json['tier'] : '';

        if ($tier === 'deployed_smoke') {
            return $this->fromTier4DeployedSmoke($json);
        }

        if ($tier === 'seeded_sql_evidence') {
            return $this->fromTier1SqlEvidence($json);
        }

        if ($tier !== '' && str_contains($tier, 'tier2')) {
            return $this->fromTier2LiveModel($json);
        }

        if (isset($json['provider_mode']) && is_string($json['provider_mode']) && isset($json['results']) && is_array($json['results'])) {
            return $this->fromTier2LiveModel($json);
        }

        if ($this->looksLikeTierZeroFixture($json)) {
            return $this->fromTierZeroFixture($json);
        }

        throw new \InvalidArgumentException('Unrecognized AgentForge eval JSON shape; missing or unknown tier.');
    }

    /**
     * @param array<string, mixed> $json
     */
    private function looksLikeTierZeroFixture(array $json): bool
    {
        if (!isset($json['results']) || !is_array($json['results'])) {
            return false;
        }

        if ($json['results'] === []) {
            return false;
        }

        $first = $json['results'][0];

        return is_array($first)
            && array_key_exists('log_context', $first)
            && array_key_exists('id', $first)
            && array_key_exists('passed', $first);
    }

    /**
     * @param array<string, mixed> $json
     */
    private function fromTierZeroFixture(array $json): NormalizedEvalRun
    {
        /** @var list<mixed> $results */
        $results = is_array($json['results'] ?? null) ? array_values($json['results']) : [];
        $caseRows = [];
        foreach ($results as $row) {
            if (!is_array($row)) {
                continue;
            }

            $id = is_string($row['id'] ?? null) ? $row['id'] : 'unknown';
            $passed = $row['passed'] === true;
            $detail = $this->tierZeroDetail($row);
            $caseRows[] = new NormalizedEvalCaseRow($id, $passed, $detail);
        }

        $passed = count(array_filter($caseRows, static fn (NormalizedEvalCaseRow $r): bool => $r->passed));
        $failed = count(array_filter($caseRows, static fn (NormalizedEvalCaseRow $r): bool => !$r->passed));
        $skipped = 0;
        $total = count($caseRows);
        $safetyFailure = isset($json['safety_failure']) && is_bool($json['safety_failure']) ? $json['safety_failure'] : null;

        $metaRows = $this->metaRowsFilterEmpty([
            ['label' => 'Fixture version', 'value' => is_string($json['fixture_version'] ?? null) ? $json['fixture_version'] : ''],
            ['label' => 'Code version', 'value' => is_string($json['code_version'] ?? null) ? $json['code_version'] : ''],
        ]);

        return new NormalizedEvalRun(
            tierKey: 'tier0_fixture',
            title: 'Tier 0 — Fixture and orchestration evals',
            audienceSummary: 'Deterministic proof that request orchestration, authorization gates, verifier behavior, refusals, and logging guardrails behave as designed. This tier does not exercise a live LLM, live SQL evidence retrieval, browser UI, or deployed HTTP/session/CSRF paths.',
            passed: $passed,
            failed: $failed,
            total: $total,
            skipped: $skipped,
            safetyFailure: $safetyFailure,
            timestamp: is_string($json['timestamp'] ?? null) ? $json['timestamp'] : '',
            codeVersion: is_string($json['code_version'] ?? null) ? $json['code_version'] : '',
            metaRows: $metaRows,
            caseRows: $caseRows,
        );
    }

    /**
     * @param array<mixed> $row
     */
    private function tierZeroDetail(array $row): string
    {
        $reason = $row['failure_reason'] ?? '';
        if (is_string($reason) && $reason !== '') {
            return $reason;
        }

        $status = $row['status'] ?? '';

        return is_string($status) ? $status : '';
    }

    /**
     * @param array<string, mixed> $json
     */
    private function fromTier1SqlEvidence(array $json): NormalizedEvalRun
    {
        /** @var list<mixed> $results */
        $results = is_array($json['results'] ?? null) ? array_values($json['results']) : [];
        $caseRows = [];
        foreach ($results as $row) {
            if (!is_array($row)) {
                continue;
            }

            $id = is_string($row['id'] ?? null) ? $row['id'] : 'unknown';
            $passed = $row['passed'] === true;
            $reason = is_string($row['failure_reason'] ?? null) ? $row['failure_reason'] : '';
            $caseRows[] = new NormalizedEvalCaseRow($id, $passed, $reason !== '' ? $reason : ($passed ? 'ok' : 'failed'));
        }

        $passed = count(array_filter($caseRows, static fn (NormalizedEvalCaseRow $r): bool => $r->passed));
        $failed = count(array_filter($caseRows, static fn (NormalizedEvalCaseRow $r): bool => !$r->passed));
        $total = count($caseRows);

        $metaRows = $this->metaRowsFilterEmpty([
            ['label' => 'Environment', 'value' => is_string($json['environment_label'] ?? null) ? $json['environment_label'] : ''],
            ['label' => 'Fixture version', 'value' => is_string($json['fixture_version'] ?? null) ? $json['fixture_version'] : ''],
            ['label' => 'Code version', 'value' => is_string($json['code_version'] ?? null) ? $json['code_version'] : ''],
        ]);

        return new NormalizedEvalRun(
            tierKey: 'tier1_sql_evidence',
            title: 'Tier 1 — Seeded SQL evidence evals',
            audienceSummary: 'Model-free proof that chart evidence is read from real database rows through the same SQL-backed tools used in production-shaped code, with patient scope and source metadata. Does not grade LLM wording.',
            passed: $passed,
            failed: $failed,
            total: $total,
            skipped: 0,
            safetyFailure: null,
            timestamp: is_string($json['timestamp'] ?? null) ? $json['timestamp'] : '',
            codeVersion: is_string($json['code_version'] ?? null) ? $json['code_version'] : '',
            metaRows: $metaRows,
            caseRows: $caseRows,
        );
    }

    /**
     * @param array<string, mixed> $json
     */
    private function fromTier2LiveModel(array $json): NormalizedEvalRun
    {
        /** @var list<mixed> $results */
        $results = is_array($json['results'] ?? null) ? array_values($json['results']) : [];
        $caseRows = [];
        foreach ($results as $row) {
            if (!is_array($row)) {
                continue;
            }

            $id = is_string($row['id'] ?? null) ? $row['id'] : 'unknown';
            $passed = $row['passed'] === true;
            $detail = $this->tierZeroDetail($row);
            if ($detail === '') {
                $detail = $passed ? 'ok' : 'failed';
            }

            $caseRows[] = new NormalizedEvalCaseRow($id, $passed, $detail);
        }

        $passed = count(array_filter($caseRows, static fn (NormalizedEvalCaseRow $r): bool => $r->passed));
        $failed = count(array_filter($caseRows, static fn (NormalizedEvalCaseRow $r): bool => !$r->passed));
        $total = count($caseRows);
        $safetyFailure = isset($json['safety_failure']) && is_bool($json['safety_failure']) ? $json['safety_failure'] : null;

        $cost = $json['aggregate_estimated_cost_usd'] ?? null;
        $costStr = is_float($cost) || is_int($cost) ? sprintf('$%.6f', (float) $cost) : (is_string($cost) ? $cost : '');

        $metaRows = $this->metaRowsFilterEmpty([
            ['label' => 'Provider mode', 'value' => is_string($json['provider_mode'] ?? null) ? $json['provider_mode'] : ''],
            ['label' => 'Model', 'value' => is_string($json['provider_model'] ?? null) ? $json['provider_model'] : ''],
            ['label' => 'Aggregate tokens in/out', 'value' => sprintf(
                '%d / %d',
                is_int($json['aggregate_input_tokens'] ?? null) ? $json['aggregate_input_tokens'] : 0,
                is_int($json['aggregate_output_tokens'] ?? null) ? $json['aggregate_output_tokens'] : 0,
            )],
            ['label' => 'Estimated cost (USD)', 'value' => $costStr],
            ['label' => 'Fixture version', 'value' => is_string($json['fixture_version'] ?? null) ? $json['fixture_version'] : ''],
            ['label' => 'Code version', 'value' => is_string($json['code_version'] ?? null) ? $json['code_version'] : ''],
        ]);

        return new NormalizedEvalRun(
            tierKey: 'tier2_live_model',
            title: 'Tier 2 — Live LLM evals',
            audienceSummary: 'Uses the real configured draft provider under verifier gates. Proves live model behavior, token usage, and cost estimates while blocking unsafe or uncited patient-specific claims from reaching the described physician-facing shape.',
            passed: $passed,
            failed: $failed,
            total: $total,
            skipped: 0,
            safetyFailure: $safetyFailure,
            timestamp: is_string($json['timestamp'] ?? null) ? $json['timestamp'] : '',
            codeVersion: is_string($json['code_version'] ?? null) ? $json['code_version'] : '',
            metaRows: $metaRows,
            caseRows: $caseRows,
        );
    }

    /**
     * @param array<string, mixed> $json
     */
    private function fromTier4DeployedSmoke(array $json): NormalizedEvalRun
    {
        /** @var list<mixed> $cases */
        $cases = is_array($json['cases'] ?? null) ? array_values($json['cases']) : [];
        $caseRows = [];
        $passed = 0;
        $failed = 0;
        $skipped = 0;
        foreach ($cases as $case) {
            if (!is_array($case)) {
                continue;
            }

            $id = is_string($case['id'] ?? null) ? $case['id'] : 'unknown';
            $verdict = is_string($case['verdict'] ?? null) ? $case['verdict'] : '';
            if ($verdict === 'pass') {
                $passed++;
                $passedRow = true;
            } elseif ($verdict === 'skipped') {
                $skipped++;
                $passedRow = false;
            } else {
                $failed++;
                $passedRow = false;
            }

            $detail = is_string($case['failure_detail'] ?? null) && $case['failure_detail'] !== ''
                ? $case['failure_detail']
                : ($verdict === 'pass' ? 'ok' : ($verdict === 'skipped' ? 'skipped' : 'fail'));

            $caseRows[] = new NormalizedEvalCaseRow($id, $passedRow, $detail);
        }

        $total = $passed + $failed + $skipped;

        $metaRows = $this->metaRowsFilterEmpty([
            ['label' => 'Deployed URL', 'value' => is_string($json['deployed_url'] ?? null) ? $json['deployed_url'] : ''],
            ['label' => 'Executor', 'value' => is_string($json['executor'] ?? null) ? $json['executor'] : ''],
            ['label' => 'Audit log assertions', 'value' => isset($json['audit_log_assertions_enabled']) && $json['audit_log_assertions_enabled'] === true ? 'enabled' : 'disabled'],
            ['label' => 'Code version', 'value' => is_string($json['code_version'] ?? null) ? $json['code_version'] : ''],
        ]);

        return new NormalizedEvalRun(
            tierKey: 'tier4_deployed_smoke',
            title: 'Tier 4 — Deployed HTTP and session smoke',
            audienceSummary: 'Automated proof of the full deployed request path: web server, PHP, OpenEMR session, CSRF validation, the chart agent controller, JSON response, and (when enabled) the PSR-3 audit log line on the VM.',
            passed: $passed,
            failed: $failed,
            total: $total,
            skipped: $skipped,
            safetyFailure: null,
            timestamp: is_string($json['executed_at_utc'] ?? null) ? $json['executed_at_utc'] : '',
            codeVersion: is_string($json['code_version'] ?? null) ? $json['code_version'] : '',
            metaRows: $metaRows,
            caseRows: $caseRows,
        );
    }

    /**
     * @param list<array{label: string, value: string}> $rows
     * @return list<array{label: string, value: string}>
     */
    private function metaRowsFilterEmpty(array $rows): array
    {
        $out = [];
        foreach ($rows as $row) {
            if ($row['value'] !== '') {
                $out[] = $row;
            }
        }

        return $out;
    }
}
