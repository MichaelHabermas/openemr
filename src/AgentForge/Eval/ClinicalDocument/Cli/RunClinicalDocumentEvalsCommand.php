<?php

declare(strict_types=1);

namespace OpenEMR\AgentForge\Eval\ClinicalDocument\Cli;

use OpenEMR\AgentForge\Eval\ClinicalDocument\Adapter\NotImplementedAdapter;
use OpenEMR\AgentForge\Eval\ClinicalDocument\Case\EvalCaseLoader;
use OpenEMR\AgentForge\Eval\ClinicalDocument\Rubric\RubricRegistry;
use OpenEMR\AgentForge\Eval\ClinicalDocument\Runner\BaselineComparator;
use OpenEMR\AgentForge\Eval\ClinicalDocument\Runner\EvalRunner;
use OpenEMR\AgentForge\Eval\ClinicalDocument\Runner\RegressionVerdict;
use OpenEMR\AgentForge\Eval\ClinicalDocument\Runner\RunArtifactWriter;
use OpenEMR\AgentForge\StringKeyedArray;
use RuntimeException;

final class RunClinicalDocumentEvalsCommand
{
    public const EXIT_OK = 0;
    public const EXIT_REGRESSION = 1;
    public const EXIT_THRESHOLD = 2;
    public const EXIT_RUNNER_ERROR = 3;

    public function run(string $repoDir): int
    {
        try {
            $fixturesDir = $repoDir . '/agent-forge/fixtures/clinical-document-golden';
            $cases = (new EvalCaseLoader())->loadDirectory($fixturesDir . '/cases');
            $thresholds = $this->loadJsonFile($fixturesDir . '/thresholds.json');
            $baseline = $this->loadJsonFile($fixturesDir . '/baseline.json');
            $result = (new EvalRunner(new NotImplementedAdapter(), new RubricRegistry()))->run($cases);
            $verdict = (new BaselineComparator())->compare($result, $thresholds, $baseline);
            $resultsDir = getenv('AGENTFORGE_CLINICAL_DOCUMENT_EVAL_RESULTS_DIR') ?: $repoDir . '/agent-forge/eval-results';
            $runDir = (new RunArtifactWriter($resultsDir))->write($result, $verdict);

            printf("Clinical document eval verdict: %s\nArtifacts: %s\n", $verdict->value, $runDir);

            return match ($verdict) {
                RegressionVerdict::BaselineMet => self::EXIT_OK,
                RegressionVerdict::RegressionExceeded => self::EXIT_REGRESSION,
                RegressionVerdict::ThresholdViolation => self::EXIT_THRESHOLD,
                RegressionVerdict::RunnerError => self::EXIT_RUNNER_ERROR,
            };
        } catch (RuntimeException $e) {
            fwrite(STDERR, 'Clinical document eval runner error: ' . $e->getMessage() . "\n");
            return self::EXIT_RUNNER_ERROR;
        }
    }

    /** @return array<string, mixed> */
    private function loadJsonFile(string $file): array
    {
        $json = file_get_contents($file);
        if ($json === false) {
            throw new RuntimeException(sprintf('Unable to read JSON file: %s', $file));
        }

        $data = json_decode($json, true);
        if (!is_array($data)) {
            throw new RuntimeException(sprintf('Invalid JSON file %s: %s', $file, json_last_error_msg()));
        }

        return StringKeyedArray::filter($data);
    }
}
