#!/usr/bin/env php
<?php

/**
 * Display recent AgentForge request traces for demo and review observability proof.
 *
 * Usage:
 *   php agent-forge/scripts/show-request-traces.php [--limit N] [--json output.json]
 *
 * Environment:
 *   AGENTFORGE_AUDIT_MODE       local | docker-compose | ssh (default: local)
 *   AGENTFORGE_AUDIT_LOG_PATH   Log file path (default: /var/log/php-error.log)
 *   AGENTFORGE_VM_SSH_HOST      SSH host (required for ssh mode)
 *   AGENTFORGE_COMPOSE_DIR      Docker compose directory (for docker-compose mode)
 *   AGENTFORGE_COMPOSE_FILE     Full path to docker-compose.yml (overrides COMPOSE_DIR)
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/vendor/autoload.php';

use OpenEMR\AgentForge\Cli\AgentForgeRepoPaths;
use OpenEMR\AgentForge\Observability\AuditLogEntryParser;
use OpenEMR\AgentForge\Observability\AuditLogTransport;
use OpenEMR\AgentForge\Observability\DockerComposeAuditLogTransport;
use OpenEMR\AgentForge\Observability\LocalFileAuditLogTransport;
use OpenEMR\AgentForge\Observability\SensitiveLogPolicy;
use OpenEMR\AgentForge\Observability\SshAuditLogTransport;

exit(agentforge_show_request_traces_main());

function agentforge_show_request_traces_main(): int
{
    $limit = 10;
    $jsonOutputPath = null;

    $args = array_slice($_SERVER['argv'] ?? [], 1);
    for ($i = 0, $count = count($args); $i < $count; $i++) {
        if ($args[$i] === '--limit' && isset($args[$i + 1])) {
            $limit = max(1, (int) $args[++$i]);
        } elseif ($args[$i] === '--json' && isset($args[$i + 1])) {
            $jsonOutputPath = $args[++$i];
        }
    }

    $mode = strtolower(trim((string) (getenv('AGENTFORGE_AUDIT_MODE') ?: 'local')));
    $logPath = (string) (getenv('AGENTFORGE_AUDIT_LOG_PATH') ?: '/var/log/php-error.log');
    $transport = agentforge_show_traces_create_transport($mode);
    if ($transport === null) {
        fwrite(STDERR, "Invalid AGENTFORGE_AUDIT_MODE: {$mode}\n");

        return 2;
    }

    $lines = $transport->grepLines('agent_forge_request', $logPath, $limit);
    if ($lines === []) {
        fwrite(STDOUT, "No agent_forge_request entries found in {$logPath} (mode: {$mode}).\n");

        return 0;
    }

    $entries = [];
    $phiLeakDetected = false;
    foreach ($lines as $line) {
        $fields = AuditLogEntryParser::extractFields($line);
        if ($fields === []) {
            continue;
        }
        if (SensitiveLogPolicy::containsForbiddenKey($fields)) {
            $phiLeakDetected = true;
        }
        $entries[] = $fields;
    }

    if ($entries === []) {
        fwrite(STDOUT, "Found {$limit} log lines but none contained parseable JSON.\n");

        return 0;
    }

    agentforge_show_traces_print_table($entries);
    agentforge_show_traces_print_stage_details($entries);

    if ($phiLeakDetected) {
        fwrite(STDERR, "\n⚠  PHI LEAK DETECTED: One or more log entries contain forbidden keys.\n");
    }

    if ($jsonOutputPath !== null) {
        $json = json_encode($entries, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        file_put_contents($jsonOutputPath, $json . "\n");
        fwrite(STDOUT, "\nJSON written to {$jsonOutputPath}\n");
    }

    fwrite(STDOUT, sprintf("\n%d trace(s) displayed (mode: %s).\n", count($entries), $mode));

    return $phiLeakDetected ? 1 : 0;
}

function agentforge_show_traces_create_transport(string $mode): ?AuditLogTransport
{
    return match ($mode) {
        'local' => new LocalFileAuditLogTransport(),
        'ssh' => agentforge_show_traces_create_ssh_transport(),
        'docker-compose' => new DockerComposeAuditLogTransport(
            agentforge_show_traces_compose_file_path(),
        ),
        default => null,
    };
}

function agentforge_show_traces_create_ssh_transport(): SshAuditLogTransport
{
    $host = getenv('AGENTFORGE_VM_SSH_HOST');
    if (!is_string($host) || $host === '') {
        fwrite(STDERR, "AGENTFORGE_VM_SSH_HOST required for ssh mode.\n");
        exit(2);
    }

    return new SshAuditLogTransport($host);
}

function agentforge_show_traces_compose_file_path(): string
{
    $composeFile = getenv('AGENTFORGE_COMPOSE_FILE');
    if (is_string($composeFile) && $composeFile !== '') {
        return $composeFile;
    }

    $repoRoot = AgentForgeRepoPaths::fromScriptsDirectory(__DIR__);
    $composeDir = getenv('AGENTFORGE_COMPOSE_DIR');
    $composeDir = is_string($composeDir) && $composeDir !== ''
        ? $composeDir
        : 'docker/development-easy';

    if (str_starts_with($composeDir, '/')) {
        return rtrim($composeDir, '/') . '/docker-compose.yml';
    }

    return $repoRoot . '/' . trim($composeDir, '/') . '/docker-compose.yml';
}

/** @param list<array<string, mixed>> $entries */
function agentforge_show_traces_print_table(array $entries): void
{
    fwrite(STDOUT, "\n## AgentForge Request Traces\n\n");
    fwrite(STDOUT, "| # | request_id   | decision | latency_ms | model           | verifier       | sources | failure_reason |\n");
    fwrite(STDOUT, "|---|--------------|----------|------------|-----------------|----------------|---------|----------------|\n");

    foreach ($entries as $i => $entry) {
        $requestId = is_string($entry['request_id'] ?? null) ? substr($entry['request_id'], 0, 12) : '—';
        $decision = is_string($entry['decision'] ?? null) ? $entry['decision'] : '—';
        $latency = is_int($entry['latency_ms'] ?? null) ? (string) $entry['latency_ms'] : '—';
        $model = is_string($entry['model'] ?? null) ? $entry['model'] : '—';
        $verifier = is_string($entry['verifier_result'] ?? null) ? $entry['verifier_result'] : '—';
        $sourceIds = is_array($entry['source_ids'] ?? null) ? (string) count($entry['source_ids']) : '0';
        $failure = is_string($entry['failure_reason'] ?? null) ? $entry['failure_reason'] : '—';

        fwrite(STDOUT, sprintf(
            "| %d | %s | %s | %s | %s | %s | %s | %s |\n",
            $i + 1,
            str_pad($requestId, 12),
            str_pad($decision, 8),
            str_pad($latency, 10),
            str_pad($model, 15),
            str_pad($verifier, 14),
            str_pad($sourceIds, 7),
            $failure,
        ));
    }
}

/** @param list<array<string, mixed>> $entries */
function agentforge_show_traces_print_stage_details(array $entries): void
{
    fwrite(STDOUT, "\n## Stage Timings\n\n");

    foreach ($entries as $i => $entry) {
        $requestId = is_string($entry['request_id'] ?? null) ? substr($entry['request_id'], 0, 12) : 'unknown';
        $timings = is_array($entry['stage_timings_ms'] ?? null) ? $entry['stage_timings_ms'] : [];

        if ($timings === []) {
            fwrite(STDOUT, sprintf("### [%d] %s — no stage timings recorded\n\n", $i + 1, $requestId));
            continue;
        }

        $latency = is_int($entry['latency_ms'] ?? null) ? $entry['latency_ms'] : null;
        $header = sprintf("### [%d] %s", $i + 1, $requestId);
        if ($latency !== null) {
            $header .= sprintf(" — total %dms", $latency);
        }
        fwrite(STDOUT, $header . "\n");

        foreach ($timings as $stage => $ms) {
            if (is_int($ms) || is_float($ms)) {
                fwrite(STDOUT, sprintf("  %s: %dms\n", $stage, (int) $ms));
            }
        }
        fwrite(STDOUT, "\n");
    }
}
