<?php

/**
 * AgentForge Tier 2 (live LLM) eval runner functions.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

use OpenEMR\AgentForge\Cli\AgentForgeRepoPaths;
use OpenEMR\AgentForge\Evidence\ToolSelectionProviderFactory;
use OpenEMR\AgentForge\Observability\RequestLog;
use OpenEMR\AgentForge\ResponseGeneration\DraftProviderConfig;
use OpenEMR\AgentForge\ResponseGeneration\DraftProviderFactory;
use OpenEMR\AgentForge\ResponseGeneration\DraftProviderMode;

require_once __DIR__ . '/eval-runner-functions.php';
require_once __DIR__ . '/script-runtime.php';

function agentforge_tier2_main(): int
{
    $repoRoot = AgentForgeRepoPaths::fromScriptsLibDirectory(__DIR__);
    $fixturePath = $repoRoot . '/agent-forge/fixtures/tier2-eval-cases.json';
    $resultsDir = agentforge_scripts_env_string('AGENTFORGE_EVAL_RESULTS_DIR', $repoRoot . '/agent-forge/eval-results');
    $deadlineMs = agentforge_scripts_env_int('AGENTFORGE_TIER2_DEADLINE_MS', 30000);

    if (!is_file($fixturePath)) {
        fwrite(STDERR, sprintf("Tier 2 fixture not found: %s\n", $fixturePath));

        return 2;
    }

    $fixture = json_decode((string) file_get_contents($fixturePath), true, 512, JSON_THROW_ON_ERROR);

    if (!agentforge_scripts_ensure_directory($resultsDir, 'eval results directory')) {
        return 2;
    }

    try {
        $config = DraftProviderConfig::fromEnvironment();
    } catch (\Throwable $e) {
        fwrite(STDERR, sprintf("Failed to build draft provider config: %s\n", $e->getMessage()));

        return 2;
    }

    if ($config->mode === DraftProviderMode::Fixture->value) {
        fwrite(STDERR, "Tier 2 refuses to run with the fixture provider.\n");
        fwrite(STDERR, "Export a real key in this shell (AGENTFORGE_OPENAI_API_KEY or AGENTFORGE_ANTHROPIC_API_KEY), or set AGENTFORGE_DRAFT_PROVIDER.\n");
        fwrite(STDERR, "Note: docker/development-easy/.env is loaded by Docker Compose into the container only — host `php` does not read it. Run from the container, e.g.:\n");
        fwrite(STDERR, "  cd docker/development-easy && docker compose exec openemr php /var/www/localhost/htdocs/openemr/agent-forge/scripts/run-tier2-evals.php\n");

        return 2;
    }

    if ($config->mode === DraftProviderMode::Disabled->value) {
        fwrite(STDERR, "Tier 2 refuses to run with the disabled provider.\n");

        return 2;
    }

    $liveProvider = DraftProviderFactory::create($config);
    $toolSelectionProvider = ToolSelectionProviderFactory::create($config);
    $startedAt = new DateTimeImmutable();
    $results = [];
    $safetyFailure = false;

    foreach ($fixture['cases'] as $case) {
        $start = hrtime(true);
        $result = agentforge_eval_run_case($case, $liveProvider, $deadlineMs, $toolSelectionProvider);
        $latencyMs = max(0, (int) floor((hrtime(true) - $start) / 1_000_000));
        $requestLog = new RequestLog(
            requestId: 'tier2-' . $case['id'],
            userId: 7,
            patientId: $result['patient_id'],
            decision: $result['decision'],
            latencyMs: $latencyMs,
            timestamp: $startedAt,
            telemetry: $result['telemetry'],
            conversationId: $result['conversation_id'],
        );

        $caseResult = agentforge_eval_evaluate_case($case, $result, $requestLog->toContext(), $latencyMs);
        $caseResult['log_context_model_is_live'] = agentforge_tier2_model_is_live($caseResult['log_context'] ?? []);
        $safetyFailure = $safetyFailure || ($case['safety_critical'] && !$caseResult['passed']);
        $results[] = $caseResult;
    }

    $totalLiveTokens = agentforge_tier2_aggregate_tokens($results);

    $summary = [
        'fixture_version' => $fixture['fixture_version'],
        'tier' => $fixture['tier'] ?? 'tier2-live-model',
        'timestamp' => $startedAt->format(DateTimeInterface::ATOM),
        'code_version' => agentforge_eval_code_version($repoRoot),
        'provider_mode' => $config->mode,
        'provider_model' => $config->model,
        'total' => count($results),
        'passed' => count(array_filter($results, static fn (array $r): bool => $r['passed'] === true)),
        'failed' => count(array_filter($results, static fn (array $r): bool => $r['passed'] !== true)),
        'safety_failure' => $safetyFailure,
        'aggregate_input_tokens' => $totalLiveTokens['input'],
        'aggregate_output_tokens' => $totalLiveTokens['output'],
        'aggregate_estimated_cost_usd' => $totalLiveTokens['cost'],
        'results' => $results,
    ];

    $resultPath = agentforge_scripts_write_eval_result($resultsDir, 'tier2-live', $startedAt, $summary);

    printf(
        "AgentForge Tier 2 (%s/%s): %d passed, %d failed. Tokens in/out: %d/%d. Estimated cost: $%.6f. Results: %s\n",
        $config->mode,
        $config->model,
        $summary['passed'],
        $summary['failed'],
        $summary['aggregate_input_tokens'],
        $summary['aggregate_output_tokens'],
        $summary['aggregate_estimated_cost_usd'] ?? 0.0,
        $resultPath,
    );

    return $summary['failed'] === 0 && !$safetyFailure ? 0 : 1;
}

/**
 * @param array<string, mixed> $logContext
 */
function agentforge_tier2_model_is_live(array $logContext): bool
{
    $model = $logContext['model'] ?? null;
    if (!is_string($model)) {
        return false;
    }

    return $model !== 'fixture-draft-provider'
        && $model !== 'not_run'
        && $model !== 'disabled-draft-provider'
        && trim($model) !== '';
}

/**
 * @param list<array<string, mixed>> $results
 * @return array{input: int, output: int, cost: float}
 */
function agentforge_tier2_aggregate_tokens(array $results): array
{
    $input = 0;
    $output = 0;
    $cost = 0.0;

    foreach ($results as $result) {
        $context = is_array($result['log_context'] ?? null) ? $result['log_context'] : [];
        $input += is_int($context['input_tokens'] ?? null) ? $context['input_tokens'] : 0;
        $output += is_int($context['output_tokens'] ?? null) ? $context['output_tokens'] : 0;
        $rowCost = $context['estimated_cost'] ?? null;
        if (is_float($rowCost) || is_int($rowCost)) {
            $cost += (float) $rowCost;
        } elseif (is_string($rowCost) && is_numeric($rowCost)) {
            $cost += (float) $rowCost;
        }
    }

    return ['input' => $input, 'output' => $output, 'cost' => $cost];
}
