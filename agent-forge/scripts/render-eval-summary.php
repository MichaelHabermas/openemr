#!/usr/bin/env php
<?php

/**
 * Render a human-readable Markdown summary from an AgentForge eval JSON file.
 *
 * Usage:
 *   php agent-forge/scripts/render-eval-summary.php --input=path/to/eval-results-*.json
 *   php agent-forge/scripts/render-eval-summary.php --input=path.json --output=summary.md
 *   php agent-forge/scripts/render-eval-summary.php --input=path.json --format=github
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

use OpenEMR\AgentForge\Reporting\EvalResultNormalizer;
use OpenEMR\AgentForge\Reporting\MarkdownEvalSummaryRenderer;

require_once dirname(__DIR__, 2) . '/vendor/autoload.php';

/**
 * @return array{input: string, output: ?string, format: string}
 */
function agentforge_render_eval_summary_parse_argv(array $argv): array
{
    $input = null;
    $output = null;
    $format = 'markdown';

    foreach (array_slice($argv, 1) as $arg) {
        if (str_starts_with($arg, '--input=')) {
            $input = substr($arg, strlen('--input='));
        } elseif (str_starts_with($arg, '--output=')) {
            $output = substr($arg, strlen('--output='));
        } elseif (str_starts_with($arg, '--format=')) {
            $format = substr($arg, strlen('--format='));
        }
    }

    if ($input === null || $input === '') {
        fwrite(STDERR, "Usage: php agent-forge/scripts/render-eval-summary.php --input=path/to.json [--output=path.md] [--format=markdown|github]\n");
        exit(2);
    }

    if ($format !== 'markdown' && $format !== 'github') {
        fwrite(STDERR, "Unknown --format; use markdown or github.\n");
        exit(2);
    }

    return ['input' => $input, 'output' => $output, 'format' => $format];
}

$opts = agentforge_render_eval_summary_parse_argv($argv);

if (!is_readable($opts['input'])) {
    fwrite(STDERR, sprintf("Input file not readable: %s\n", $opts['input']));
    exit(2);
}

$raw = file_get_contents($opts['input']);
if ($raw === false) {
    fwrite(STDERR, sprintf("Failed to read: %s\n", $opts['input']));
    exit(2);
}

try {
    /** @var array<string, mixed> $json */
    $json = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
} catch (Throwable $e) {
    fwrite(STDERR, sprintf("Invalid JSON: %s\n", $e->getMessage()));
    exit(2);
}

try {
    $normalizer = new EvalResultNormalizer();
    $run = $normalizer->fromDecodedJson($json);
} catch (Throwable $e) {
    fwrite(STDERR, sprintf("Could not normalize eval JSON: %s\n", $e->getMessage()));
    exit(2);
}

$renderer = new MarkdownEvalSummaryRenderer();
$markdown = $renderer->render($run);

if ($opts['output'] !== null && $opts['output'] !== '') {
    if (file_put_contents($opts['output'], $markdown) === false) {
        fwrite(STDERR, sprintf("Failed to write: %s\n", $opts['output']));
        exit(2);
    }
} else {
    echo $markdown;
}

exit(0);
