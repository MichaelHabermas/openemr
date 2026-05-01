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

final class DraftVerifier
{
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

            if ($claim->type === DraftClaim::TYPE_PATIENT_FACT) {
                if (!$this->claimMatchesAllSources($claim, $itemsBySourceId)) {
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
        $claimText = $this->normalize($claim->text);
        foreach ($claim->citedSourceIds as $sourceId) {
            if (!isset($itemsBySourceId[$sourceId])) {
                return false;
            }

            $item = $itemsBySourceId[$sourceId];
            if (
                !str_contains($claimText, $this->normalize($item->displayLabel))
                || !str_contains($claimText, $this->normalize($item->value))
            ) {
                return false;
            }
        }

        return true;
    }

    private function normalize(string $value): string
    {
        $value = strtolower($value);
        $value = preg_replace('/\s+/', ' ', $value) ?? $value;

        return trim($value);
    }
}
