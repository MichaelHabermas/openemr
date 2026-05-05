<?php

/**
 * Clinical document eval support for AgentForge.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\AgentForge\Eval\ClinicalDocument\Runner;

use OpenEMR\AgentForge\Eval\ClinicalDocument\Adapter\ExtractionSystemAdapter;
use OpenEMR\AgentForge\Eval\ClinicalDocument\Case\EvalCase;
use OpenEMR\AgentForge\Eval\ClinicalDocument\Rubric\RubricInputs;
use OpenEMR\AgentForge\Eval\ClinicalDocument\Rubric\RubricRegistry;
use OpenEMR\AgentForge\Eval\ClinicalDocument\Rubric\RubricStatus;

final class EvalRunner
{
    public function __construct(
        private readonly ExtractionSystemAdapter $adapter,
        private readonly RubricRegistry $rubrics,
    ) {
    }

    /** @param list<EvalCase> $cases */
    public function run(array $cases): EvalRunResult
    {
        $caseResults = [];
        $summaryBuckets = [];

        foreach ($this->rubrics->all() as $rubric) {
            $summaryBuckets[$rubric->name()] = [
                RubricStatus::Pass->value => 0,
                RubricStatus::Fail->value => 0,
                RubricStatus::NotApplicable->value => 0,
            ];
        }

        foreach ($cases as $case) {
            $output = $this->adapter->runCase($case);
            $rubricResults = [];
            foreach ($this->rubrics->all() as $rubric) {
                $result = $rubric->evaluate(new RubricInputs($case, $output));
                $rubricResults[$result->name] = [
                    'status' => $result->status->value,
                    'reason' => $result->reason,
                ];
                $summaryBuckets[$result->name][$result->status->value]++;
            }

            $caseResults[] = [
                'case_id' => $case->caseId,
                'category' => $case->category->value,
                'adapter_status' => $output->status,
                'failure_reason' => $output->failureReason,
                'rubrics' => $rubricResults,
            ];
        }

        $summaries = [];
        foreach ($summaryBuckets as $name => $bucket) {
            $applicable = $bucket[RubricStatus::Pass->value] + $bucket[RubricStatus::Fail->value];
            $passRate = $applicable === 0 ? 1.0 : $bucket[RubricStatus::Pass->value] / $applicable;
            $summaries[$name] = new RubricSummary(
                $name,
                $bucket[RubricStatus::Pass->value],
                $bucket[RubricStatus::Fail->value],
                $bucket[RubricStatus::NotApplicable->value],
                $passRate,
            );
        }

        return new EvalRunResult($caseResults, $summaries);
    }
}
