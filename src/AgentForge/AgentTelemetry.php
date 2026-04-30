<?php

/**
 * PHI-minimized AgentForge execution telemetry.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\AgentForge;

final readonly class AgentTelemetry
{
    /**
     * @param list<string> $toolsCalled
     * @param list<string> $sourceIds
     */
    public function __construct(
        public string $questionType,
        public array $toolsCalled,
        public array $sourceIds,
        public string $model,
        public int $inputTokens,
        public int $outputTokens,
        public ?float $estimatedCost,
        public ?string $failureReason,
        public string $verifierResult,
    ) {
    }

    /**
     * @return array{
     *     question_type: string,
     *     tools_called: list<string>,
     *     source_ids: list<string>,
     *     model: string,
     *     input_tokens: int,
     *     output_tokens: int,
     *     estimated_cost: ?float,
     *     failure_reason: ?string,
     *     verifier_result: string
     * }
     */
    public function toContext(): array
    {
        return [
            'question_type' => $this->questionType,
            'tools_called' => $this->toolsCalled,
            'source_ids' => $this->sourceIds,
            'model' => $this->model,
            'input_tokens' => $this->inputTokens,
            'output_tokens' => $this->outputTokens,
            'estimated_cost' => $this->estimatedCost,
            'failure_reason' => $this->failureReason,
            'verifier_result' => $this->verifierResult,
        ];
    }

    public static function notRun(?string $failureReason): self
    {
        return new self(
            'not_classified',
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
}
