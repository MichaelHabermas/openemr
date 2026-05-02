<?php

/**
 * PHI-minimized AgentForge execution telemetry.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\AgentForge\Observability;

final readonly class AgentTelemetry
{
    /**
     * @param list<string> $toolsCalled
     * @param list<string> $skippedChartSections
     * @param list<string> $sourceIds
     * @param array<string, int> $stageTimingsMs
     */
    public function __construct(
        public string $questionType,
        public array $toolsCalled,
        public array $skippedChartSections,
        public array $sourceIds,
        public string $model,
        public int $inputTokens,
        public int $outputTokens,
        public ?float $estimatedCost,
        public ?string $failureReason,
        public string $verifierResult,
        public array $stageTimingsMs = [],
    ) {
    }

    /**
     * @return array{
     *     question_type: string,
     *     tools_called: list<string>,
     *     skipped_chart_sections: list<string>,
     *     source_ids: list<string>,
     *     model: string,
     *     input_tokens: int,
     *     output_tokens: int,
     *     estimated_cost: ?float,
     *     failure_reason: ?string,
     *     verifier_result: string,
     *     stage_timings_ms: array<string, int>
     * }
     */
    public function toContext(): array
    {
        return [
            'question_type' => $this->questionType,
            'tools_called' => $this->toolsCalled,
            'skipped_chart_sections' => $this->skippedChartSections,
            'source_ids' => $this->sourceIds,
            'model' => $this->model,
            'input_tokens' => $this->inputTokens,
            'output_tokens' => $this->outputTokens,
            'estimated_cost' => $this->estimatedCost,
            'failure_reason' => $this->failureReason,
            'verifier_result' => $this->verifierResult,
            'stage_timings_ms' => $this->stageTimingsMs,
        ];
    }

    /** @param array<string, int> $stageTimingsMs */
    public function withStageTimings(array $stageTimingsMs): self
    {
        return new self(
            $this->questionType,
            $this->toolsCalled,
            $this->skippedChartSections,
            $this->sourceIds,
            $this->model,
            $this->inputTokens,
            $this->outputTokens,
            $this->estimatedCost,
            $this->failureReason,
            $this->verifierResult,
            $stageTimingsMs,
        );
    }

    public static function notRun(?string $failureReason): self
    {
        return new self(
            'not_classified',
            [],
            [],
            [],
            'not_run',
            0,
            0,
            null,
            $failureReason,
            'not_run',
        );
    }

    /**
     * Telemetry when the chart question planner refuses before evidence collection or drafting.
     */
    public static function clinicalAdviceRefusal(string $questionType): self
    {
        return self::plannedRefusal($questionType, 'clinical_advice_refusal');
    }

    /** @param list<string> $skippedChartSections */
    public static function plannedRefusal(
        string $questionType,
        string $failureReason,
        array $skippedChartSections = [],
    ): self
    {
        return new self(
            $questionType,
            [],
            $skippedChartSections,
            [],
            'not_run',
            0,
            0,
            null,
            $failureReason,
            'not_run',
        );
    }
}
