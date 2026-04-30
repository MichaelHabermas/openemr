<?php

/**
 * Agent handler that drafts from evidence and returns only verified output.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\AgentForge;

use DomainException;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use RuntimeException;

final class VerifiedAgentHandler implements AgentHandler, AgentTelemetryProvider
{
    private AgentForgeClock $clock;
    private ?AgentTelemetry $lastTelemetry = null;

    /**
     * @param list<ChartEvidenceTool> $tools
     */
    public function __construct(
        private array $tools,
        private DraftProvider $draftProvider,
        private DraftVerifier $verifier,
        private LoggerInterface $logger = new NullLogger(),
        ?AgentForgeClock $clock = null,
        private int $deadlineMs = 8000,
    ) {
        $this->clock = $clock ?? new SystemAgentForgeClock();
    }

    public function handle(AgentRequest $request): AgentResponse
    {
        $this->lastTelemetry = null;
        $refusal = ClinicalAdviceRefusalPolicy::refusalFor($request->question->value);
        if ($refusal !== null) {
            $this->lastTelemetry = new AgentTelemetry(
                questionType: 'clinical_advice_refusal',
                toolsCalled: [],
                sourceIds: [],
                model: 'not_run',
                inputTokens: 0,
                outputTokens: 0,
                estimatedCost: null,
                failureReason: 'clinical_advice_refusal',
                verifierResult: 'not_run',
            );
            return AgentResponse::refusal($refusal);
        }

        try {
            $toolsCalled = [];
            $bundle = EvidenceBundle::fromEvidenceResults($this->collectEvidence(
                $request,
                $this->clock->nowMs(),
                $toolsCalled,
            ));
            $draft = $this->draftWithOneRetry($request, $bundle);
            $result = $this->verifier->verify($draft, $bundle);
            $this->lastTelemetry = $this->telemetryFromRun($request, $bundle, $draft->usage, $result, $toolsCalled);
        } catch (DomainException | RuntimeException $exception) {
            $this->logger->error(
                'AgentForge verified drafting failed unexpectedly.',
                [
                    'exception' => $exception,
                    'patient_id' => $request->patientId->value,
                ],
            );

            $this->lastTelemetry = new AgentTelemetry(
                questionType: $this->classifyQuestion($request),
                toolsCalled: [],
                sourceIds: [],
                model: 'not_run',
                inputTokens: 0,
                outputTokens: 0,
                estimatedCost: null,
                failureReason: 'verified_drafting_failed',
                verifierResult: 'not_run',
            );

            return AgentResponse::unexpectedFailure();
        }

        if (!$result->passed) {
            $this->lastTelemetry = new AgentTelemetry(
                questionType: $this->classifyQuestion($request),
                toolsCalled: $toolsCalled,
                sourceIds: $bundle->sourceIds(),
                model: $draft->usage->model,
                inputTokens: $draft->usage->inputTokens,
                outputTokens: $draft->usage->outputTokens,
                estimatedCost: $draft->usage->estimatedCost,
                failureReason: 'verification_failed',
                verifierResult: 'failed',
            );
            return AgentResponse::refusal('The draft answer could not be verified.');
        }

        return $this->toAgentResponse($draft, $result);
    }

    public function lastTelemetry(): ?AgentTelemetry
    {
        return $this->lastTelemetry;
    }

    /**
     * @param list<string> $toolsCalled
     * @return list<EvidenceResult>
     */
    private function collectEvidence(AgentRequest $request, int $startMs, array &$toolsCalled): array
    {
        $results = [];
        foreach ($this->tools as $tool) {
            $toolsCalled[] = $tool->section();
            try {
                $results[] = $tool->collect($request->patientId);
            } catch (DomainException | RuntimeException $exception) {
                $this->logger->error(
                    'AgentForge evidence tool failed unexpectedly.',
                    [
                        'exception' => $exception,
                        'tool' => $tool::class,
                        'patient_id' => $request->patientId->value,
                    ],
                );
                $results[] = EvidenceResult::failure(
                    $tool->section(),
                    sprintf('%s could not be checked.', $tool->section()),
                );
            }

            if ($this->deadlineExceeded($startMs)) {
                $results[] = EvidenceResult::failure(
                    'Deadline',
                    'Some chart sections could not be checked before the deadline.',
                );
                break;
            }
        }

        return $results;
    }

    /** @param list<string> $toolsCalled */
    private function telemetryFromRun(
        AgentRequest $request,
        EvidenceBundle $bundle,
        DraftUsage $usage,
        VerificationResult $result,
        array $toolsCalled,
    ): AgentTelemetry {
        return new AgentTelemetry(
            questionType: $this->classifyQuestion($request),
            toolsCalled: array_values(array_unique($toolsCalled)),
            sourceIds: $bundle->sourceIds(),
            model: $usage->model,
            inputTokens: $usage->inputTokens,
            outputTokens: $usage->outputTokens,
            estimatedCost: $usage->estimatedCost,
            failureReason: $result->passed ? null : 'verification_failed',
            verifierResult: $result->passed ? 'passed' : 'failed',
        );
    }

    private function classifyQuestion(AgentRequest $request): string
    {
        $question = strtolower($request->question->value);
        if (str_contains($question, 'medication') || str_contains($question, 'metformin')) {
            return 'medication';
        }
        if (str_contains($question, 'a1c') || str_contains($question, 'lab')) {
            return 'lab';
        }
        if (str_contains($question, 'plan') || str_contains($question, 'note')) {
            return 'last_plan';
        }
        if (str_contains($question, 'briefing') || str_contains($question, 'changed')) {
            return 'visit_briefing';
        }

        return 'chart_question';
    }

    private function deadlineExceeded(int $startMs): bool
    {
        return $this->deadlineMs >= 0 && ($this->clock->nowMs() - $startMs) > $this->deadlineMs;
    }

    private function draftWithOneRetry(AgentRequest $request, EvidenceBundle $bundle): DraftResponse
    {
        try {
            return $this->draftProvider->draft($request, $bundle);
        } catch (DomainException) {
            return $this->draftProvider->draft($request, $bundle);
        }
    }

    private function toAgentResponse(DraftResponse $draft, VerificationResult $result): AgentResponse
    {
        $verified = array_fill_keys($result->verifiedSentenceIds, true);
        $lines = [];
        foreach ($draft->sentences as $sentence) {
            if (isset($verified[$sentence->id])) {
                $lines[] = $sentence->text;
            }
        }

        return new AgentResponse(
            'ok',
            implode("\n", $lines),
            $result->citations,
            $result->missingOrUncheckedSections,
            $result->refusalsOrWarnings,
        );
    }
}
