<?php

/**
 * Clinical document eval support for AgentForge.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\AgentForge\Eval\ClinicalDocument\Cli;

use OpenEMR\AgentForge\Eval\ClinicalDocument\Adapter\ClinicalDocumentExtractionAdapter;
use OpenEMR\AgentForge\Eval\ClinicalDocument\Case\EvalCaseLoader;
use OpenEMR\AgentForge\Eval\ClinicalDocument\Rubric\RubricRegistry;
use OpenEMR\AgentForge\Eval\ClinicalDocument\Runner\BaselineComparator;
use OpenEMR\AgentForge\Eval\ClinicalDocument\Runner\EvalRunner;
use OpenEMR\AgentForge\Eval\ClinicalDocument\Runner\RegressionVerdict;
use OpenEMR\AgentForge\Eval\ClinicalDocument\Runner\RunArtifactWriter;
use OpenEMR\AgentForge\Eval\ClinicalDocument\Runner\StructuralCoveragePolicy;
use OpenEMR\AgentForge\StringKeyedArray;
use OpenEMR\AgentForge\Time\SystemMonotonicClock;
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
            $rubrics = new RubricRegistry();
            $coverageViolations = (new StructuralCoveragePolicy())->validate($cases, $rubrics);
            if ($coverageViolations !== []) {
                throw new RuntimeException("Clinical document golden coverage policy failed:\n- " . implode("\n- ", $coverageViolations));
            }

            $adapter = new ClinicalDocumentExtractionAdapter($repoDir, $fixturesDir . '/extraction', new SystemMonotonicClock());
            $result = (new EvalRunner($adapter, $rubrics))->run($cases);
            $verdict = (new BaselineComparator())->compare($result, $thresholds, $baseline);
            $resultsDir = getenv('AGENTFORGE_CLINICAL_DOCUMENT_EVAL_RESULTS_DIR') ?: $repoDir . '/agent-forge/eval-results';
            $runDir = (new RunArtifactWriter($resultsDir))->write(
                $result,
                $verdict,
                $this->runMetadata($cases, $thresholds, $baseline),
            );

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

    /**
     * @param list<\OpenEMR\AgentForge\Eval\ClinicalDocument\Case\EvalCase> $cases
     * @param array<string, mixed> $thresholds
     * @param array<string, mixed> $baseline
     * @return array<string, mixed>
     */
    private function runMetadata(array $cases, array $thresholds, array $baseline): array
    {
        $categoryCounts = [];
        $coverageTagCounts = [];
        foreach ($cases as $case) {
            $category = $case->category->value;
            $categoryCounts[$category] = ($categoryCounts[$category] ?? 0) + 1;
            foreach ($case->coverageTags as $tag) {
                $coverageTagCounts[$tag] = ($coverageTagCounts[$tag] ?? 0) + 1;
            }
        }
        ksort($categoryCounts);
        ksort($coverageTagCounts);

        $rubricThresholds = is_array($thresholds['rubric_thresholds'] ?? null) ? $thresholds['rubric_thresholds'] : [];

        return [
            'case_count_policy' => '50-60',
            'case_count' => count($cases),
            'baseline_case_count' => is_numeric($baseline['case_count'] ?? null) ? (int) $baseline['case_count'] : null,
            'threshold_rubrics' => array_keys($rubricThresholds),
            'category_counts' => $categoryCounts,
            'coverage_tag_counts' => $coverageTagCounts,
            'structural_coverage_policy' => 'passed',
        ];
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
