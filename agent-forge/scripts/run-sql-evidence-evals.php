#!/usr/bin/env php
<?php

/**
 * Run seeded SQL evidence evals against real AgentForge SQL evidence tools.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

use OpenEMR\AgentForge\Auth\PatientAuthorizationGate;
use OpenEMR\AgentForge\Auth\SqlPatientAccessRepository;
use OpenEMR\AgentForge\Cli\AgentForgeRepoPaths;
use OpenEMR\AgentForge\DefaultQueryExecutor;
use OpenEMR\AgentForge\Eval\SqlEvidenceEvalCaseRepository;
use OpenEMR\AgentForge\Eval\SqlEvidenceEvalRunner;
use OpenEMR\AgentForge\Evidence\EvidenceToolFactory;
use OpenEMR\AgentForge\Evidence\SqlChartEvidenceRepository;

require_once dirname(__DIR__, 2) . '/vendor/autoload.php';
require_once __DIR__ . '/lib/eval-runner-functions.php';
require_once __DIR__ . '/lib/script-runtime.php';

$repoRoot = AgentForgeRepoPaths::fromScriptsDirectory(__DIR__);
$groundTruthPath = $repoRoot . '/agent-forge/fixtures/demo-patient-ground-truth.json';
$resultsDir = agentforge_scripts_env_string(
    'AGENTFORGE_SQL_EVAL_RESULTS_DIR',
    agentforge_scripts_env_string('AGENTFORGE_EVAL_RESULTS_DIR', $repoRoot . '/agent-forge/eval-results'),
);
$environmentLabel = agentforge_scripts_env_string('AGENTFORGE_SQL_EVAL_ENVIRONMENT', 'local');
$GLOBALS['OE_SITE_DIR'] = agentforge_scripts_env_string('OE_SITE_DIR', $repoRoot . '/sites/default');

if (!agentforge_scripts_ensure_directory($resultsDir, 'SQL eval results directory')) {
    exit(2);
}

try {
    (new DefaultQueryExecutor())->fetchRecords('SELECT 1 AS agentforge_sql_eval_preflight');
} catch (Throwable $exception) {
    fwrite(STDERR, sprintf("SQL evidence eval preflight failed: %s\n", $exception->getMessage()));
    fwrite(STDERR, "No SQL evidence result file was written.\n");
    fwrite(STDERR, "Hint: this script needs OpenEMR's PHP DB bootstrap (not only mysql CLI seeding). From docker/development-easy run:\n");
    fwrite(STDERR, "  docker compose exec -T openemr php /var/www/localhost/htdocs/openemr/agent-forge/scripts/run-sql-evidence-evals.php\n");
    exit(2);
}

$caseRepository = new SqlEvidenceEvalCaseRepository();
$runner = new SqlEvidenceEvalRunner(
    EvidenceToolFactory::createDefault(new SqlChartEvidenceRepository()),
    new PatientAuthorizationGate(new SqlPatientAccessRepository()),
);
$summary = $runner->run(
    $caseRepository->load($groundTruthPath),
    $caseRepository->fixtureVersion($groundTruthPath),
    agentforge_eval_code_version($repoRoot),
    $environmentLabel,
);

$resultPath = agentforge_scripts_write_eval_result(
    $resultsDir,
    'sql-evidence-eval-results',
    new DateTimeImmutable('now'),
    $summary,
);

printf(
    "AgentForge seeded SQL evidence evals: %d passed, %d failed. Results: %s\n",
    $summary['passed'],
    $summary['failed'],
    $resultPath,
);

exit($summary['failed'] === 0 ? 0 : 1);
