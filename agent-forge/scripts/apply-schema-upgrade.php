#!/usr/bin/env php
<?php

/**
 * Apply OpenEMR SQL upgrade files from the CLI.
 *
 * All directives in the upgrade SQL are idempotent (#IfNotTable,
 * #IfMissingColumn, etc.), so this script is safe to run repeatedly.
 *
 * Usage:
 *   php agent-forge/scripts/apply-schema-upgrade.php [--site=default] [upgrade-file ...]
 *
 * When no upgrade files are given, applies the current development upgrade
 * (8_1_0-to-8_1_1_upgrade.sql).
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

use OpenEMR\Services\Utils\SQLUpgradeService;

if (PHP_SAPI !== 'cli') {
    echo "CLI only.\n";
    exit(1);
}

ini_set('display_errors', '0');
ini_set('log_errors', '1');
error_reporting(E_ALL & ~E_NOTICE & ~E_WARNING & ~E_DEPRECATED & ~E_USER_NOTICE & ~E_USER_WARNING & ~E_USER_DEPRECATED);

$repoRoot = dirname(__DIR__, 2);
require_once $repoRoot . '/vendor/autoload.php';

$site = 'default';
$files = [];
foreach (array_slice($_SERVER['argv'], 1) as $arg) {
    if (str_starts_with($arg, '--site=')) {
        $site = substr($arg, strlen('--site='));
    } else {
        $files[] = $arg;
    }
}

if ($files === []) {
    $files = ['8_1_0-to-8_1_1_upgrade.sql'];
}

$_GET['site'] = $site === '' ? 'default' : $site;
$ignoreAuth = true;
$sessionAllowWrite = true;
require_once $repoRoot . '/interface/globals.php';

ini_set('display_errors', '0');
ini_set('log_errors', '1');

$service = new SQLUpgradeService();
$service->setRenderOutputToScreen(false);
$service->setThrowExceptionOnError(true);

$failed = 0;
foreach ($files as $file) {
    fprintf(STDOUT, "Applying %s ...\n", $file);
    try {
        $service->upgradeFromSqlFile($file);
        $output = $service->getRenderOutputBuffer();
        $skipped = 0;
        foreach ($output as $line) {
            if (str_contains($line, 'Skipping section')) {
                $skipped++;
            }
        }
        $total = count($output);
        fprintf(STDOUT, "  %s: %d directives processed, %d already present\n", $file, $total, $skipped);
    } catch (\Throwable $e) {
        fprintf(STDERR, "  FAIL %s: %s\n", $file, $e->getMessage());
        $failed++;
    }
}

if ($failed > 0) {
    fprintf(STDERR, "Schema upgrade failed: %d file(s) had errors.\n", $failed);
    exit(1);
}

fprintf(STDOUT, "Schema upgrade complete.\n");
