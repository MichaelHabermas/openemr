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
