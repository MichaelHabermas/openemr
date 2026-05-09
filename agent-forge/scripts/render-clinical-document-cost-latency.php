#!/usr/bin/env php
<?php

/**
 * Render the Week 2 clinical document cost/latency report from artifacts.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/vendor/autoload.php';

use OpenEMR\AgentForge\Cli\AgentForgeRepoPaths;
use OpenEMR\AgentForge\Reporting\ClinicalDocumentCostLatencyArtifactNormalizer;
use OpenEMR\AgentForge\Reporting\ClinicalDocumentCostLatencyReportRenderer;

$repoDir = AgentForgeRepoPaths::fromScriptsDirectory(__DIR__);
$options = getopt('', [
    'clinical-run:',
    'clinical-summary:',
    'tier2::',
    'deployed-smoke::',
    'output:',
]);

$clinicalRun = (string) ($options['clinical-run'] ?? $repoDir . '/agent-forge/eval-results/clinical-document-20260508-190800/run.json');
$clinicalSummary = (string) ($options['clinical-summary'] ?? $repoDir . '/agent-forge/eval-results/clinical-document-20260508-190800/summary.json');
$tier2 = isset($options['tier2']) ? (string) $options['tier2'] : $repoDir . '/agent-forge/eval-results/tier2-live-20260503-202550.json';
$deployedSmoke = isset($options['deployed-smoke']) ? (string) $options['deployed-smoke'] : $repoDir . '/agent-forge/eval-results/deployed-smoke-20260503-201547.json';
$output = (string) ($options['output'] ?? $repoDir . '/agent-forge/docs/operations/CLINICAL-DOCUMENT-COST-LATENCY.md');

$run = (new ClinicalDocumentCostLatencyArtifactNormalizer())->normalize(
    $clinicalRun,
    $clinicalSummary,
    $tier2,
    $deployedSmoke,
);
$markdown = (new ClinicalDocumentCostLatencyReportRenderer())->render($run);

if (file_put_contents($output, $markdown) === false) {
    fwrite(STDERR, sprintf("Unable to write report: %s\n", $output));
    exit(1);
}

printf("Rendered clinical document cost/latency report: %s\n", $output);
