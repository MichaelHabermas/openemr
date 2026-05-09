<?php

/**
 * Verifies latency tracking infrastructure is present in eval output.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\AgentForge\Eval\ClinicalDocument\Rubric;

final class LatencyBudgetRubric implements Rubric
{
    public function name(): string
    {
        return 'latency_budget';
    }

    public function evaluate(RubricInputs $inputs): RubricResult
    {
        $handoffs = $inputs->output->answer['handoffs'] ?? null;
        if (!is_array($handoffs) || $handoffs === []) {
            return new RubricResult(
                $this->name(),
                RubricStatus::NotApplicable,
                'No handoffs present; latency tracking not applicable.',
            );
        }

        foreach ($handoffs as $handoff) {
            if (!is_array($handoff)) {
                continue;
            }
            if (!array_key_exists('latency_ms', $handoff)) {
                return new RubricResult(
                    $this->name(),
                    RubricStatus::Fail,
                    'Handoff is missing latency_ms field.',
                );
            }
            $latencyMs = $handoff['latency_ms'];
            if ($latencyMs !== null && !is_int($latencyMs)) {
                return new RubricResult(
                    $this->name(),
                    RubricStatus::Fail,
                    'latency_ms is present but not an integer or null.',
                );
            }
        }

        return new RubricResult(
            $this->name(),
            RubricStatus::Pass,
            'Latency tracking fields are present in all handoffs.',
        );
    }
}
