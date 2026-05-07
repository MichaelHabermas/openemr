<?php

/**
 * Shared runtime helpers for AgentForge script entry points.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

use OpenEMR\AgentForge\Reporting\EvalLatestSummaryWriter;

function agentforge_scripts_load_compose_dotenv(string $repoRoot): void
{
    $composeDir = getenv('AGENTFORGE_COMPOSE_DIR');
    $composeDir = is_string($composeDir) && $composeDir !== ''
        ? $composeDir
        : 'docker/development-easy';

    $envPath = str_starts_with($composeDir, '/')
        ? rtrim($composeDir, '/') . '/.env'
        : $repoRoot . '/' . trim($composeDir, '/') . '/.env';

    if (!is_file($envPath)) {
        return;
    }

    $lines = file($envPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if (!is_array($lines)) {
        return;
    }

    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#') {
            continue;
        }
        $eqPos = strpos($line, '=');
        if ($eqPos === false) {
            continue;
        }
        $key = trim(substr($line, 0, $eqPos));
        $value = trim(substr($line, $eqPos + 1));
        if ($key === '') {
            continue;
        }
        // Strip optional surrounding quotes.
        if (strlen($value) >= 2 && ($value[0] === '"' || $value[0] === "'") && $value[0] === $value[strlen($value) - 1]) {
            $value = substr($value, 1, -1);
        }
        // Only set if not already present — explicit exports win.
        $existing = getenv($key);
        if (!is_string($existing) || $existing === '') {
            putenv("{$key}={$value}");
        }
    }
}

function agentforge_scripts_env_string(string $name, string $default = ''): string
{
    $value = getenv($name);

    return is_string($value) && $value !== '' ? $value : $default;
}

function agentforge_scripts_env_int(string $name, int $default): int
{
    $value = getenv($name);
    if (!is_string($value) || $value === '') {
        return $default;
    }

    return (int) $value;
}

function agentforge_scripts_env_nullable_int(string $name): ?int
{
    $value = getenv($name);
    if (!is_string($value) || $value === '') {
        return null;
    }

    return (int) $value;
}

function agentforge_scripts_env_truthy_legacy(string $name): bool
{
    $value = getenv($name);
    if (!is_string($value) || $value === '') {
        return false;
    }

    // Preserve legacy PHP truthiness behavior used by existing script checks.
    return (bool) $value;
}

function agentforge_scripts_ensure_directory(string $directoryPath, string $errorContext): bool
{
    if (!is_dir($directoryPath) && !mkdir($directoryPath, 0775, true) && !is_dir($directoryPath)) {
        fwrite(STDERR, sprintf("Failed to create %s: %s\n", $errorContext, $directoryPath));

        return false;
    }

    return true;
}

/**
 * @param array<string, mixed> $summary
 */
function agentforge_scripts_write_eval_result(
    string $resultsDir,
    string $filePrefix,
    DateTimeImmutable $startedAt,
    array $summary,
    bool $updateLatestSummary = true,
): string {
    $resultPath = sprintf(
        '%s/%s-%s.json',
        rtrim($resultsDir, '/'),
        $filePrefix,
        $startedAt->format('Ymd-His'),
    );
    file_put_contents($resultPath, json_encode($summary, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . "\n");
    if ($updateLatestSummary) {
        EvalLatestSummaryWriter::tryWriteFromEvalJsonFile($resultPath);
    }

    return $resultPath;
}
