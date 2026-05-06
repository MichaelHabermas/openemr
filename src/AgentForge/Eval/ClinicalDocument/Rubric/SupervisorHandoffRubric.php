<?php

/**
 * Clinical document eval support for AgentForge.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\AgentForge\Eval\ClinicalDocument\Rubric;

final class SupervisorHandoffRubric implements Rubric
{
    public function name(): string
    {
        return 'supervisor_handoff';
    }

    public function evaluate(RubricInputs $inputs): RubricResult
    {
        if ($inputs->case->expectedRubrics->expectedFor($this->name()) === null) {
            return new RubricResult($this->name(), RubricStatus::NotApplicable, 'Supervisor handoff checks are not required.');
        }

        $requiredTypes = $inputs->case->expectedAnswer->requiredHandoffTypes;
        if ($requiredTypes === []) {
            return new RubricResult($this->name(), RubricStatus::Pass, 'No supervisor handoffs were required.');
        }

        $handoffs = $inputs->output->answer['handoffs'] ?? null;
        if (!is_array($handoffs) || $handoffs === []) {
            return new RubricResult($this->name(), RubricStatus::Fail, 'Machine-readable supervisor handoffs were not reported.');
        }

        $seenTypes = [];
        foreach ($handoffs as $handoff) {
            if (!is_array($handoff)) {
                return new RubricResult($this->name(), RubricStatus::Fail, 'Supervisor handoff was not a structured object.');
            }
            foreach (['source_node', 'destination_node', 'decision_reason', 'task_type', 'outcome', 'latency_ms', 'error_reason'] as $field) {
                if (!array_key_exists($field, $handoff)) {
                    return new RubricResult($this->name(), RubricStatus::Fail, sprintf('Supervisor handoff is missing %s.', $field));
                }
            }
            foreach (['source_node', 'destination_node', 'decision_reason', 'task_type', 'outcome'] as $field) {
                if (!is_string($handoff[$field]) || trim($handoff[$field]) === '') {
                    return new RubricResult($this->name(), RubricStatus::Fail, 'Supervisor handoff fields must be machine-readable strings.');
                }
            }
            if (!in_array($handoff['source_node'], ['supervisor', 'intake-extractor', 'evidence-retriever'], true)) {
                return new RubricResult($this->name(), RubricStatus::Fail, 'Supervisor handoff source node is not recognized.');
            }
            if (!in_array($handoff['destination_node'], ['supervisor', 'intake-extractor', 'evidence-retriever'], true)) {
                return new RubricResult($this->name(), RubricStatus::Fail, 'Supervisor handoff destination node is not recognized.');
            }
            if ($handoff['latency_ms'] !== null && !is_int($handoff['latency_ms'])) {
                return new RubricResult($this->name(), RubricStatus::Fail, 'Supervisor handoff latency must be an integer or null.');
            }
            if ($handoff['error_reason'] !== null && !is_string($handoff['error_reason'])) {
                return new RubricResult($this->name(), RubricStatus::Fail, 'Supervisor handoff fields must be machine-readable strings.');
            }
            $seenTypes[] = $handoff['task_type'];
        }

        foreach ($requiredTypes as $requiredType) {
            if (!in_array($requiredType, $seenTypes, true)) {
                return new RubricResult($this->name(), RubricStatus::Fail, sprintf('Supervisor handoff is missing required type "%s".', $requiredType));
            }
        }

        return new RubricResult($this->name(), RubricStatus::Pass, 'Supervisor handoffs are structured and complete.');
    }
}
