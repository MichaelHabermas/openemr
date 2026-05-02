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
use OpenEMR\AgentForge\Deadline;
use OpenEMR\AgentForge\Evidence\EvidenceBundle;
use OpenEMR\AgentForge\Evidence\EvidenceBundleItem;
use OpenEMR\AgentForge\ResponseGeneration\DraftProvider;
use OpenEMR\AgentForge\ResponseGeneration\DraftProviderException;
use OpenEMR\AgentForge\ResponseGeneration\DraftResponse;
use OpenEMR\AgentForge\ResponseGeneration\DraftUsage;
use OpenEMR\AgentForge\StageTimer;
use OpenEMR\AgentForge\SystemAgentForgeClock;
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

    /**
     * @param list<string> $toolsCalled
     * @param list<string> $skippedChartSections
     */
    public function run(
        AgentRequest $request,
        EvidenceBundle $bundle,
        string $questionType,
        array $toolsCalled,
        array $skippedChartSections = [],
        ?StageTimer $timer = null,
        ?Deadline $deadline = null,
    ): VerifiedDraftingResult {
        $deadline ??= new Deadline(new SystemAgentForgeClock(), -1);
        $knownMissing = KnownMissingDataPolicy::missingSectionsFor($request->question, $bundle);

        if ($knownMissing !== []) {
            return $this->knownMissingResponse(
                $questionType,
                $toolsCalled,
                $skippedChartSections,
                $bundle,
                $knownMissing,
            );
        }

        if ($bundle->items === []) {
            return new VerifiedDraftingResult(
                $this->emptyBundleResponse($bundle),
                $this->telemetry(
                    $questionType,
                    $toolsCalled,
                    $skippedChartSections,
                    $bundle,
                    DraftUsage::notRun(),
                    'empty_evidence_bundle',
                    'not_run',
                ),
            );
        }

        try {
            $timer?->start('draft');
            $draft = $this->draftWithOneRetry($request, $bundle, $deadline);
            $timer?->stop('draft');
            $timer?->start('verify');
            $result = $this->verifier->verify($draft, $bundle);
            $timer?->stop('verify');
        } catch (DraftProviderException $exception) {
            $timer?->stop('draft');
            $timer?->stop('verify');
            return $this->loggedDraftFailure(
                $request,
                $questionType,
                $toolsCalled,
                $skippedChartSections,
                $bundle,
                'AgentForge draft provider failed unexpectedly.',
                $exception,
                AgentResponse::refusal('The model draft provider could not be reached. Please try again.'),
                'draft_provider_unavailable',
            );
        } catch (DomainException | RuntimeException $exception) {
            $timer?->stop('draft');
            $timer?->stop('verify');
            return $this->loggedDraftFailure(
                $request,
                $questionType,
                $toolsCalled,
                $skippedChartSections,
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
                    $skippedChartSections,
                    $bundle,
                    $draft->usage,
                    'verification_failed',
                    'failed',
                ),
            );
        }

        return new VerifiedDraftingResult(
            $this->toAgentResponse($draft, $result, $questionType, $bundle),
            $this->telemetry(
                $questionType,
                $toolsCalled,
                $skippedChartSections,
                $bundle,
                $draft->usage,
                null,
                'passed',
            ),
        );
    }

    /**
     * @param list<string> $toolsCalled
     * @param list<string> $skippedChartSections
     * @param list<string> $knownMissing
     */
    private function knownMissingResponse(
        string $questionType,
        array $toolsCalled,
        array $skippedChartSections,
        EvidenceBundle $bundle,
        array $knownMissing,
    ): VerifiedDraftingResult {
        $missingOrUnchecked = array_values(array_unique(array_merge(
            $bundle->missingSections,
            $knownMissing,
            $bundle->failedSections,
        )));

        return new VerifiedDraftingResult(
            new AgentResponse(
                'ok',
                implode("\n", $missingOrUnchecked),
                [],
                $missingOrUnchecked,
                [],
            ),
            $this->telemetry(
                $questionType,
                $toolsCalled,
                $skippedChartSections,
                $bundle,
                DraftUsage::notRun(),
                null,
                'passed',
            ),
        );
    }

    private function emptyBundleResponse(EvidenceBundle $bundle): AgentResponse
    {
        $missingOrUnchecked = array_values(array_unique(array_merge(
            $bundle->missingSections,
            $bundle->failedSections,
        )));
        $lines = $missingOrUnchecked === []
            ? ['No chart evidence was found for the checked sections.']
            : $missingOrUnchecked;

        return new AgentResponse(
            'ok',
            implode("\n", $lines),
            [],
            $missingOrUnchecked,
            [],
        );
    }

    private function draftWithOneRetry(AgentRequest $request, EvidenceBundle $bundle, Deadline $deadline): DraftResponse
    {
        try {
            return $this->draftProvider->draft($request, $bundle, $deadline);
        } catch (DomainException) {
            // One retry: transient validation gaps from the provider should not immediately fail the visit.
            return $this->draftProvider->draft($request, $bundle, $deadline);
        }
    }

    /**
     * @param list<string> $toolsCalled
     * @param list<string> $skippedChartSections
     */
    private function loggedDraftFailure(
        AgentRequest $request,
        string $questionType,
        array $toolsCalled,
        array $skippedChartSections,
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
                $skippedChartSections,
                $bundle,
                DraftUsage::notRun(),
                $failureReason,
                'not_run',
            ),
        );
    }

    /**
     * @param list<string> $toolsCalled
     * @param list<string> $skippedChartSections
     */
    private function telemetry(
        string $questionType,
        array $toolsCalled,
        array $skippedChartSections,
        EvidenceBundle $bundle,
        DraftUsage $usage,
        ?string $failureReason,
        string $verifierResult,
    ): AgentTelemetry {
        return new AgentTelemetry(
            questionType: $questionType,
            toolsCalled: array_values(array_unique($toolsCalled)),
            skippedChartSections: array_values(array_unique($skippedChartSections)),
            sourceIds: $bundle->sourceIds(),
            model: $usage->model,
            inputTokens: $usage->inputTokens,
            outputTokens: $usage->outputTokens,
            estimatedCost: $usage->estimatedCost,
            failureReason: $failureReason,
            verifierResult: $verifierResult,
        );
    }

    private function toAgentResponse(
        DraftResponse $draft,
        VerificationResult $result,
        string $questionType,
        EvidenceBundle $bundle,
    ): AgentResponse
    {
        $verified = array_fill_keys($result->verifiedSentenceIds, true);
        $lines = [];
        foreach ($draft->sentences as $sentence) {
            if (isset($verified[$sentence->id])) {
                $lines[] = $sentence->text;
            }
        }

        $citations = $result->citations;
        if ($questionType === 'visit_briefing') {
            foreach ($this->missingMedicationLines($lines, $bundle) as $line => $sourceId) {
                $lines[] = $line;
                $citations[] = $sourceId;
            }
        }

        return new AgentResponse(
            'ok',
            implode("\n", $lines),
            array_values(array_unique($citations)),
            $result->missingOrUncheckedSections,
            $result->refusalsOrWarnings,
        );
    }

    /**
     * @param list<string> $lines
     * @return array<string, string>
     */
    private function missingMedicationLines(array $lines, EvidenceBundle $bundle): array
    {
        $answerText = "\n" . implode("\n", $lines) . "\n";
        $missingLines = [];

        foreach ($bundle->items as $item) {
            if ($item->sourceType !== 'medication') {
                continue;
            }

            $line = $this->evidenceLine($item);
            if (str_contains($answerText, "\n" . $line . "\n")) {
                continue;
            }

            $missingLines[$line] = $item->sourceId;
        }

        return $missingLines;
    }

    private function evidenceLine(EvidenceBundleItem $item): string
    {
        return sprintf('%s: %s', $item->displayLabel, $item->value);
    }
}
