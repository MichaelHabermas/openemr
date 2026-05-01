<?php

/**
 * Drafts from bounded evidence and returns only verified AgentForge output.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\AgentForge\Handlers;

use DomainException;
use OpenEMR\AgentForge\AgentTelemetry;
use OpenEMR\AgentForge\Evidence\EvidenceBundle;
use OpenEMR\AgentForge\ResponseGeneration\DraftProvider;
use OpenEMR\AgentForge\ResponseGeneration\DraftProviderException;
use OpenEMR\AgentForge\ResponseGeneration\DraftResponse;
use OpenEMR\AgentForge\ResponseGeneration\DraftUsage;
use OpenEMR\AgentForge\Verification\DraftVerifier;
use OpenEMR\AgentForge\Verification\KnownMissingDataPolicy;
use OpenEMR\AgentForge\Verification\VerificationResult;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use RuntimeException;

final readonly class VerifiedDraftingPipeline
{
    public function __construct(
        private DraftProvider $draftProvider,
        private DraftVerifier $verifier,
        private LoggerInterface $logger = new NullLogger(),
    ) {
    }

    /** @param list<string> $toolsCalled */
    public function run(
        AgentRequest $request,
        EvidenceBundle $bundle,
        string $questionType,
        array $toolsCalled,
    ): VerifiedDraftingResult {
        $bundle = $this->bundleWithKnownMissingSections($request, $bundle);

        try {
            $draft = $this->draftWithOneRetry($request, $bundle);
            $result = $this->verifier->verify($draft, $bundle);
        } catch (DraftProviderException $exception) {
            return $this->loggedDraftFailure(
                $request,
                $questionType,
                $toolsCalled,
                $bundle,
                'AgentForge draft provider failed unexpectedly.',
                $exception,
                AgentResponse::refusal('The model draft provider could not be reached. Please try again.'),
                'draft_provider_unavailable',
            );
        } catch (DomainException | RuntimeException $exception) {
            return $this->loggedDraftFailure(
                $request,
                $questionType,
                $toolsCalled,
                $bundle,
                'AgentForge verified drafting failed unexpectedly.',
                $exception,
                AgentResponse::unexpectedFailure(),
                'verified_drafting_failed',
            );
        }

        if (!$result->passed) {
            return new VerifiedDraftingResult(
                AgentResponse::refusal('The draft answer could not be verified.'),
                $this->telemetry(
                    $questionType,
                    $toolsCalled,
                    $bundle,
                    $draft->usage,
                    'verification_failed',
                    'failed',
                ),
            );
        }

        return new VerifiedDraftingResult(
            $this->toAgentResponse($draft, $result),
            $this->telemetry($questionType, $toolsCalled, $bundle, $draft->usage, null, 'passed'),
        );
    }

    private function bundleWithKnownMissingSections(AgentRequest $request, EvidenceBundle $bundle): EvidenceBundle
    {
        $knownMissing = KnownMissingDataPolicy::missingSectionsFor($request->question, $bundle);
        if ($knownMissing === []) {
            return $bundle;
        }

        return new EvidenceBundle(
            $bundle->items,
            array_values(array_unique(array_merge($bundle->missingSections, $knownMissing))),
            $bundle->failedSections,
        );
    }

    private function draftWithOneRetry(AgentRequest $request, EvidenceBundle $bundle): DraftResponse
    {
        try {
            return $this->draftProvider->draft($request, $bundle);
        } catch (DomainException) {
            // One retry: transient validation gaps from the provider should not immediately fail the visit.
            return $this->draftProvider->draft($request, $bundle);
        }
    }

    /**
     * @param list<string> $toolsCalled
     */
    private function loggedDraftFailure(
        AgentRequest $request,
        string $questionType,
        array $toolsCalled,
        EvidenceBundle $bundle,
        string $logMessage,
        object $exception,
        AgentResponse $response,
        string $failureReason,
    ): VerifiedDraftingResult {
        $this->logger->error($logMessage, [
            'failure_class' => $exception::class,
            'patient_id' => $request->patientId->value,
        ]);

        return new VerifiedDraftingResult(
            $response,
            $this->telemetry(
                $questionType,
                $toolsCalled,
                $bundle,
                DraftUsage::notRun(),
                $failureReason,
                'not_run',
            ),
        );
    }

    /** @param list<string> $toolsCalled */
    private function telemetry(
        string $questionType,
        array $toolsCalled,
        EvidenceBundle $bundle,
        DraftUsage $usage,
        ?string $failureReason,
        string $verifierResult,
    ): AgentTelemetry {
        return new AgentTelemetry(
            questionType: $questionType,
            toolsCalled: array_values(array_unique($toolsCalled)),
            sourceIds: $bundle->sourceIds(),
            model: $usage->model,
            inputTokens: $usage->inputTokens,
            outputTokens: $usage->outputTokens,
            estimatedCost: $usage->estimatedCost,
            failureReason: $failureReason,
            verifierResult: $verifierResult,
        );
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
