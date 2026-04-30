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

final readonly class VerifiedAgentHandler implements AgentHandler
{
    private AgentForgeClock $clock;

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
        $refusal = ClinicalAdviceRefusalPolicy::refusalFor($request->question->value);
        if ($refusal !== null) {
            return AgentResponse::refusal($refusal);
        }

        try {
            $bundle = EvidenceBundle::fromEvidenceResults($this->collectEvidence($request, $this->clock->nowMs()));
            $draft = $this->draftWithOneRetry($request, $bundle);
            $result = $this->verifier->verify($draft, $bundle);
        } catch (DomainException | RuntimeException $exception) {
            $this->logger->error(
                'AgentForge verified drafting failed unexpectedly.',
                [
                    'exception' => $exception,
                    'patient_id' => $request->patientId->value,
                ],
            );

            return AgentResponse::unexpectedFailure();
        }

        if (!$result->passed) {
            return AgentResponse::refusal('The draft answer could not be verified.');
        }

        return $this->toAgentResponse($draft, $result);
    }

    /** @return list<EvidenceResult> */
    private function collectEvidence(AgentRequest $request, int $startMs): array
    {
        $results = [];
        foreach ($this->tools as $tool) {
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
