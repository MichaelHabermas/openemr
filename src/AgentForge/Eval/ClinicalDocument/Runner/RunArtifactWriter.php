<?php

declare(strict_types=1);

namespace OpenEMR\AgentForge\Eval\ClinicalDocument\Runner;

use RuntimeException;

final class RunArtifactWriter
{
    public function __construct(private readonly string $resultsDir)
    {
    }

    public function write(EvalRunResult $result, RegressionVerdict $verdict): string
    {
        $runDir = rtrim($this->resultsDir, '/') . '/clinical-document-' . gmdate('Ymd-His');
        if (!is_dir($runDir) && !mkdir($runDir, 0775, true) && !is_dir($runDir)) {
            throw new RuntimeException(sprintf('Unable to create Clinical document eval results directory: %s', $runDir));
        }

        $run = [
            'tier' => 'clinical_document_mvp_gate',
            'executed_at_utc' => gmdate('c'),
            'cases' => $result->caseResults,
        ];
        $summary = [
            'tier' => 'clinical_document_mvp_gate',
            'executed_at_utc' => gmdate('c'),
            'verdict' => $verdict->value,
            'rubrics' => array_map(
                static fn (RubricSummary $summary): array => [
                    'passed' => $summary->passed,
                    'failed' => $summary->failed,
                    'not_applicable' => $summary->notApplicable,
                    'pass_rate' => $summary->passRate,
                ],
                $result->rubricSummaries,
            ),
        ];

        $this->writeJson($runDir . '/run.json', $run);
        $this->writeJson($runDir . '/summary.json', $summary);

        return $runDir;
    }

    /** @param array<string, mixed> $data */
    private function writeJson(string $file, array $data): void
    {
        $encoded = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if ($encoded === false || file_put_contents($file, $encoded . "\n") === false) {
            throw new RuntimeException(sprintf('Unable to write Clinical document eval artifact: %s', $file));
        }
    }
}
