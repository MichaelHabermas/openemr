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
use OpenEMR\AgentForge\Deadline;
use OpenEMR\AgentForge\Evidence\EvidenceBundle;
use OpenEMR\AgentForge\Evidence\EvidenceBundleItem;
use OpenEMR\AgentForge\Observability\AgentTelemetry;
use OpenEMR\AgentForge\Observability\StageTimer;
use OpenEMR\AgentForge\ResponseGeneration\DraftClaim;
use OpenEMR\AgentForge\ResponseGeneration\DraftProvider;
use OpenEMR\AgentForge\ResponseGeneration\DraftProviderException;
use OpenEMR\AgentForge\ResponseGeneration\DraftResponse;
use OpenEMR\AgentForge\ResponseGeneration\DraftUsage;
use OpenEMR\AgentForge\ResponseGeneration\FixtureDraftProvider;
use OpenEMR\AgentForge\SystemAgentForgeClock;
use OpenEMR\AgentForge\Verification\DraftVerifier;
use OpenEMR\AgentForge\Verification\KnownMissingDataPolicy;
use OpenEMR\AgentForge\Verification\VerificationResult;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use RuntimeException;

final readonly class VerifiedDraftingPipeline
{
    private const FIXTURE_DRAFT_WARNING = 'Model drafting is disabled; deterministic fixture drafting was used.';

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
            $fallback = $this->providerUnavailableFallback(
                $request,
                $questionType,
                $toolsCalled,
                $skippedChartSections,
                $bundle,
                $exception,
            );
            if ($fallback !== null) {
                return $fallback;
            }

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
            if ($draft->usage->model !== DraftUsage::fixture()->model) {
                $fallbackDraft = (new FixtureDraftProvider())->draft($request, $bundle, $deadline);
                $fallbackResult = $this->trustedFixtureResult($fallbackDraft, $bundle);
                if ($fallbackResult->passed) {
                    $response = $this->toAgentResponse($fallbackDraft, $fallbackResult, $questionType, $bundle);

                    return new VerifiedDraftingResult(
                        new AgentResponse(
                            $response->status,
                            $response->answer,
                            $response->citations,
                            $response->missingOrUncheckedSections,
                            array_values(array_unique(array_merge(
                                ['The model draft could not be verified; deterministic evidence fallback was used.'],
                                $fallbackResult->refusalsOrWarnings,
                            ))),
                            null,
                            $response->sections,
                            $response->citationDetails,
                        ),
                        $this->telemetry(
                            $questionType,
                            $toolsCalled,
                            $skippedChartSections,
                            $bundle,
                            $draft->usage,
                            'model_verification_failed_fallback_used',
                            'fallback_passed',
                        ),
                    );
                }
            }

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
     */
    private function providerUnavailableFallback(
        AgentRequest $request,
        string $questionType,
        array $toolsCalled,
        array $skippedChartSections,
        EvidenceBundle $bundle,
        DraftProviderException $exception,
    ): ?VerifiedDraftingResult {
        $this->logger->error('AgentForge draft provider failed; deterministic evidence fallback attempted.', [
            'failure_class' => $exception::class,
            'patient_id' => $request->patientId->value,
        ]);

        try {
            $fallbackDraft = (new FixtureDraftProvider())->draft(
                $request,
                $bundle,
                new Deadline(new SystemAgentForgeClock(), -1),
            );
            $fallbackResult = $this->trustedFixtureResult($fallbackDraft, $bundle);
        } catch (DomainException | RuntimeException) {
            return null;
        }

        if (!$fallbackResult->passed) {
            return null;
        }

        $response = $this->toAgentResponse($fallbackDraft, $fallbackResult, $questionType, $bundle);

        return new VerifiedDraftingResult(
            new AgentResponse(
                $response->status,
                $response->answer,
                $response->citations,
                $response->missingOrUncheckedSections,
                array_values(array_unique(array_merge(
                    ['The model draft provider could not be reached; deterministic evidence fallback was used.'],
                    array_values(array_filter(
                        $fallbackResult->refusalsOrWarnings,
                        static fn (string $warning): bool => $warning !== self::FIXTURE_DRAFT_WARNING,
                    )),
                ))),
                null,
                $response->sections,
                $response->citationDetails,
            ),
            $this->telemetry(
                $questionType,
                $toolsCalled,
                $skippedChartSections,
                $bundle,
                DraftUsage::notRun(),
                'draft_provider_unavailable_fallback_used',
                'fallback_passed',
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
                null,
                [['title' => 'Missing or unchecked', 'content' => implode("\n", $missingOrUnchecked)]],
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
            null,
            [['title' => 'Missing or unchecked', 'content' => implode("\n", $lines)]],
            [],
        );
    }

    private function trustedFixtureResult(DraftResponse $draft, EvidenceBundle $bundle): VerificationResult
    {
        $verifiedSentenceIds = [];
        $citations = [];

        foreach ($draft->claims as $claim) {
            $verifiedSentenceIds[] = $claim->sentenceId;
            if (in_array($claim->type, [DraftClaim::TYPE_PATIENT_FACT, DraftClaim::TYPE_GUIDELINE_EVIDENCE], true)) {
                $citations = array_merge($citations, $claim->citedSourceIds);
            }
        }

        $verifiedSentenceIds = array_values(array_unique($verifiedSentenceIds));
        $missingOrUnchecked = array_values(array_unique(array_merge(
            $draft->missingSections,
            $bundle->missingSections,
            $bundle->failedSections,
        )));

        return new VerificationResult(
            $verifiedSentenceIds !== [],
            $verifiedSentenceIds,
            array_values(array_unique($citations)),
            $missingOrUnchecked,
            array_values(array_unique($draft->refusalsOrWarnings)),
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
        foreach ($this->missingEvidenceLines($questionType, $lines, $bundle, $citations) as $line => $sourceId) {
            $lines[] = $line;
            $citations[] = $sourceId;
        }

        return new AgentResponse(
            'ok',
            implode("\n", $lines),
            array_values(array_unique($citations)),
            $result->missingOrUncheckedSections,
            $result->refusalsOrWarnings,
            null,
            $this->sectionsFor($questionType, $lines, $result->missingOrUncheckedSections),
            $this->citationDetails($bundle, $citations),
        );
    }

    /**
     * @param list<string> $lines
     * @param list<string> $missingOrUnchecked
     * @return list<array{title: string, content: string}>
     */
    private function sectionsFor(string $questionType, array $lines, array $missingOrUnchecked): array
    {
        $sections = [];
        if ($lines !== []) {
            $sections[] = [
                'title' => $this->sectionTitle($questionType),
                'content' => implode("\n", $lines),
            ];
        }
        if ($missingOrUnchecked !== []) {
            $sections[] = [
                'title' => 'Missing or unchecked',
                'content' => implode("\n", $missingOrUnchecked),
            ];
        }

        return $sections;
    }

    private function sectionTitle(string $questionType): string
    {
        return match ($questionType) {
            'visit_briefing' => 'Visit briefing',
            'medication' => 'Medications',
            'lab' => 'Labs',
            'demographics' => 'Demographics',
            default => 'Answer',
        };
    }

    /**
     * @param list<string> $citations
     * @return list<array<string, mixed>>
     */
    private function citationDetails(EvidenceBundle $bundle, array $citations): array
    {
        $itemsBySourceId = $bundle->itemsBySourceId();
        $details = [];
        foreach (array_values(array_unique($citations)) as $sourceId) {
            $item = $itemsBySourceId[$sourceId] ?? null;
            if ($item !== null) {
                $details[] = $item->toArray();
            }
        }

        return $details;
    }

    /**
     * @param list<string> $lines
     * @param list<string> $citations
     * @return array<string, string>
     */
    private function missingEvidenceLines(string $questionType, array $lines, EvidenceBundle $bundle, array $citations): array
    {
        $sourceTypes = match ($questionType) {
            'visit_briefing' => [
                'demographic',
                'encounter',
                'problem',
                'medication',
                'allergy',
                'lab',
                'vital',
                'note',
            ],
            'medication' => ['medication'],
            'follow_up_change_review' => ['document', 'guideline'],
            default => [],
        };

        if ($sourceTypes === []) {
            return [];
        }

        return $this->missingLinesForSourceTypes($lines, $bundle, $citations, $sourceTypes);
    }

    /**
     * @param list<string> $lines
     * @param list<string> $citations
     * @param list<string> $sourceTypes
     * @return array<string, string>
     */
    private function missingLinesForSourceTypes(array $lines, EvidenceBundle $bundle, array $citations, array $sourceTypes): array
    {
        $answerText = "\n" . implode("\n", $lines) . "\n";
        $cited = array_fill_keys($citations, true);
        $allowedSourceTypes = array_fill_keys($sourceTypes, true);
        $missingLines = [];

        foreach ($bundle->items as $item) {
            if (!isset($allowedSourceTypes[$item->sourceType])) {
                continue;
            }

            if (isset($cited[$item->sourceId])) {
                continue;
            }

            if ($item->sourceType === 'note' && $this->unsafeToEcho($item)) {
                continue;
            }

            $line = sprintf('%s: %s', $item->displayLabel, $item->value);
            if (str_contains($answerText, "\n" . $line . "\n") || str_contains($answerText, $item->sourceId)) {
                continue;
            }

            $missingLines[$line] = $item->sourceId;
        }

        return $missingLines;
    }

    private function unsafeToEcho(EvidenceBundleItem $item): bool
    {
        $text = strtolower($item->displayLabel . ' ' . $item->value);
        foreach (
            [
                'ignore safety',
                'ignore prior',
                'disregard previous',
                'system prompt',
                'prescribe',
                'patient ssn',
                'patient ssns',
            ] as $unsafeNeedle
        ) {
            if (str_contains($text, $unsafeNeedle)) {
                return true;
            }
        }

        return false;
    }
}
