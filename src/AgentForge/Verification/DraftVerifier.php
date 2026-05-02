<?php

/**
 * Deterministic verifier for structured AgentForge drafts.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\AgentForge\Verification;

use OpenEMR\AgentForge\Evidence\EvidenceBundle;
use OpenEMR\AgentForge\Evidence\EvidenceBundleItem;
use OpenEMR\AgentForge\ResponseGeneration\DraftClaim;
use OpenEMR\AgentForge\ResponseGeneration\DraftResponse;

final readonly class DraftVerifier
{
    public function __construct(
        private EvidenceMatcher $matcher = new EvidenceMatcher(),
    ) {
    }

    public function verify(DraftResponse $draft, EvidenceBundle $bundle): VerificationResult
    {
        $itemsBySourceId = $bundle->itemsBySourceId();
        $verifiedSentenceIds = [];
        $rejectedSentenceIds = [];
        $citations = [];
        $warnings = $draft->refusalsOrWarnings;
        $refusalSentences = [];

        foreach ($draft->claims as $claim) {
            if ($claim->type === DraftClaim::TYPE_REFUSAL) {
                $refusalSentences[$claim->sentenceId] = true;
            }
        }

        foreach ($draft->sentences as $sentence) {
            $unsafeRefusal = ClinicalAdviceRefusalPolicy::refusalFor($sentence->text);
            if ($unsafeRefusal !== null && !isset($refusalSentences[$sentence->id])) {
                $rejectedSentenceIds[] = $sentence->id;
                $warnings[] = $unsafeRefusal;
            }
        }

        foreach ($draft->claims as $claim) {
            $unsafeRefusal = ClinicalAdviceRefusalPolicy::refusalFor($claim->text);
            if ($unsafeRefusal !== null && $claim->type !== DraftClaim::TYPE_REFUSAL) {
                $rejectedSentenceIds[] = $claim->sentenceId;
                $warnings[] = $unsafeRefusal;
                continue;
            }

            if ($this->claimRequiresGrounding($claim, $itemsBySourceId)) {
                if (
                    !$this->claimMatchesAllSources($claim, $itemsBySourceId)
                    || !$this->groundedClaimsCoverDisplayedSentence($claim->sentenceId, $draft, $itemsBySourceId)
                ) {
                    $rejectedSentenceIds[] = $claim->sentenceId;
                    $warnings[] = 'Some draft content was omitted because it could not be verified against the chart evidence.';
                    continue;
                }
                $verifiedSentenceIds[] = $claim->sentenceId;
                $citations = array_merge($citations, $claim->citedSourceIds);
                continue;
            }

            $verifiedSentenceIds[] = $claim->sentenceId;
        }

        $rejected = array_fill_keys($rejectedSentenceIds, true);
        $verifiedSentenceIds = array_values(array_filter(
            array_unique($verifiedSentenceIds),
            static fn (string $sentenceId): bool => !isset($rejected[$sentenceId]),
        ));
        $citations = array_values(array_unique($citations));
        $missingOrUnchecked = array_values(array_unique(array_merge(
            $draft->missingSections,
            $bundle->missingSections,
            $bundle->failedSections,
        )));

        return new VerificationResult(
            $verifiedSentenceIds !== [],
            $verifiedSentenceIds,
            $citations,
            $missingOrUnchecked,
            array_values(array_unique($warnings)),
        );
    }

    /** @param array<string, EvidenceBundleItem> $itemsBySourceId */
    private function claimMatchesAllSources(DraftClaim $claim, array $itemsBySourceId): bool
    {
        if ($claim->citedSourceIds === []) {
            return false;
        }

        foreach ($claim->citedSourceIds as $sourceId) {
            if (!isset($itemsBySourceId[$sourceId])) {
                return false;
            }

            if (!$this->matcher->matches($claim->text, $itemsBySourceId[$sourceId])) {
                return false;
            }
        }

        return true;
    }

    /** @param array<string, EvidenceBundleItem> $itemsBySourceId */
    private function claimRequiresGrounding(DraftClaim $claim, array $itemsBySourceId): bool
    {
        if ($claim->type === DraftClaim::TYPE_PATIENT_FACT || $claim->citedSourceIds !== []) {
            return true;
        }

        $claimText = $this->normalize($claim->text);
        if ($this->isAllowedNonPatientBoilerplate($claimText)) {
            return false;
        }

        foreach ($itemsBySourceId as $item) {
            if (
                str_contains($claimText, $this->normalize($item->displayLabel))
                || str_contains($claimText, $this->normalize($item->value))
            ) {
                return true;
            }
        }

        return (bool) preg_match(
            '/\b(a1c|hemoglobin|lab|labs|medication|medications|metformin|dose|mg|diagnosis|problem|problems|demographic|dob|allergy|allergies|allergic|reaction|severity|vital|vitals|blood\s+pressure|bp|pulse|temperature|respiration|oxygen|o2|weight|height|bmi)\b/',
            $claimText,
        );
    }

    private function isAllowedNonPatientBoilerplate(string $claimText): bool
    {
        if (
            str_contains($claimText, 'could not be checked')
            || str_contains($claimText, 'not found in the chart')
            || str_contains($claimText, 'no chart evidence was found')
            || str_contains($claimText, 'cannot provide diagnosis')
            || str_contains($claimText, 'model drafting is disabled')
        ) {
            return true;
        }

        return false;
    }

    /** @param array<string, EvidenceBundleItem> $itemsBySourceId */
    private function groundedClaimsCoverDisplayedSentence(
        string $sentenceId,
        DraftResponse $draft,
        array $itemsBySourceId,
    ): bool {
        $sentenceText = $this->sentenceText($sentenceId, $draft);
        if ($sentenceText === null) {
            return false;
        }

        $uncovered = $this->normalize($this->stripCitationBrackets($sentenceText));
        foreach ($draft->claims as $candidate) {
            if ($candidate->sentenceId !== $sentenceId || !$this->claimRequiresGrounding($candidate, $itemsBySourceId)) {
                continue;
            }
            if (!$this->claimMatchesAllSources($candidate, $itemsBySourceId)) {
                return false;
            }
            $uncovered = str_replace($this->normalize($candidate->text), ' ', $uncovered);
            foreach ($candidate->citedSourceIds as $sourceId) {
                $item = $itemsBySourceId[$sourceId] ?? null;
                if ($item !== null && in_array($item->sourceType, ['medication', 'allergy', 'vital'], true)) {
                    $uncovered = str_replace($this->normalize($item->displayLabel), ' ', $uncovered);
                }
            }
        }

        return $this->containsOnlyConnectiveText($uncovered);
    }

    private function containsOnlyConnectiveText(string $text): bool
    {
        $text = preg_replace(
            '/\b(the\s+)?(active\s+)?(prescriptions|medications|meds)\s+(are|include|includes|listed|found|shown)\b/',
            ' ',
            $text,
        ) ?? $text;
        $text = preg_replace(
            '/\b(the\s+)?recent\s+(lab|labs|results|hemoglobin\s+a1c\s+results)\s+(are|include|includes|show|shows|listed|found)\b/',
            ' ',
            $text,
        ) ?? $text;
        $text = preg_replace(
            '/\b(the\s+)?(active\s+)?allergies\s+(are|include|includes|listed|found|shown)\b/',
            ' ',
            $text,
        ) ?? $text;
        $text = preg_replace(
            '/\b(the\s+)?recent\s+(vital|vitals|vital\s+signs)\s+(are|include|includes|show|shows|listed|found)\b/',
            ' ',
            $text,
        ) ?? $text;
        $text = preg_replace('/\b(and|or|with|plus)\b/', ' ', $text) ?? $text;
        $text = preg_replace('/[[:punct:]\s]+/', '', $text) ?? $text;

        return $text === '';
    }

    private function sentenceText(string $sentenceId, DraftResponse $draft): ?string
    {
        foreach ($draft->sentences as $sentence) {
            if ($sentence->id === $sentenceId) {
                return $sentence->text;
            }
        }

        return null;
    }

    private function stripCitationBrackets(string $text): string
    {
        return preg_replace('/\s*\[[^\]]+\]/', '', $text) ?? $text;
    }

    private function normalize(string $value): string
    {
        $value = strtolower($value);
        $value = preg_replace('/\s+/', ' ', $value) ?? $value;

        return trim($value);
    }
}
