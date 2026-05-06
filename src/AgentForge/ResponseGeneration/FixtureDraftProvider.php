<?php

/**
 * Deterministic fixture draft provider for model-off Epic 6 behavior.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\AgentForge\ResponseGeneration;

use OpenEMR\AgentForge\Deadline;
use OpenEMR\AgentForge\Evidence\EvidenceBundle;
use OpenEMR\AgentForge\Handlers\AgentRequest;
use OpenEMR\AgentForge\Verification\ClinicalAdviceRefusalPolicy;
use OpenEMR\AgentForge\Verification\KnownMissingDataPolicy;

final class FixtureDraftProvider implements DraftProvider
{
    public function draft(AgentRequest $request, EvidenceBundle $bundle, Deadline $deadline): DraftResponse
    {
        $questionMissingSections = KnownMissingDataPolicy::missingSectionsFor($request->question, $bundle);
        $missingSections = array_values(array_unique(array_merge(
            $bundle->missingSections,
            $questionMissingSections,
        )));

        $refusal = ClinicalAdviceRefusalPolicy::refusalFor($request->question->value);
        if ($refusal !== null) {
            return DraftResponse::singleRefusal($refusal, DraftUsage::fixture(), $missingSections);
        }

        if ($questionMissingSections !== []) {
            $sentences = [];
            $claims = [];
            $index = 1;
            foreach ($missingSections as $missingSection) {
                $sentenceId = sprintf('missing-%d', $index);
                $sentences[] = new DraftSentence($sentenceId, $missingSection);
                $claims[] = new DraftClaim($missingSection, DraftClaim::TYPE_MISSING_DATA, [], $sentenceId);
                ++$index;
            }

            return new DraftResponse(
                $sentences,
                $claims,
                $missingSections,
                ['Model drafting is disabled; deterministic fixture drafting was used.'],
                DraftUsage::fixture(),
            );
        }

        $sentences = [];
        $claims = [];
        $index = 1;
        foreach ($bundle->items as $item) {
            $sentenceId = sprintf('s%d', $index);
            $text = sprintf('%s: %s [%s]', $item->displayLabel, $item->value, $item->sourceId);
            $sentences[] = new DraftSentence($sentenceId, $text);
            $claimType = $item->sourceType === 'guideline'
                ? DraftClaim::TYPE_GUIDELINE_EVIDENCE
                : DraftClaim::TYPE_PATIENT_FACT;
            $claims[] = new DraftClaim(
                sprintf('%s: %s', $item->displayLabel, $item->value),
                $claimType,
                [$item->sourceId],
                $sentenceId,
            );
            ++$index;
        }

        foreach ($missingSections as $missingSection) {
            $sentenceId = sprintf('missing-%d', $index);
            $sentences[] = new DraftSentence($sentenceId, $missingSection);
            $claims[] = new DraftClaim($missingSection, DraftClaim::TYPE_MISSING_DATA, [], $sentenceId);
            ++$index;
        }

        foreach ($bundle->failedSections as $failedSection) {
            $sentenceId = sprintf('failed-%d', $index);
            $sentences[] = new DraftSentence($sentenceId, $failedSection);
            $claims[] = new DraftClaim($failedSection, DraftClaim::TYPE_WARNING, [], $sentenceId);
            ++$index;
        }

        if ($sentences === []) {
            $sentences[] = new DraftSentence('missing-1', 'No chart evidence was found for the checked sections.');
            $claims[] = new DraftClaim(
                'No chart evidence was found for the checked sections.',
                DraftClaim::TYPE_MISSING_DATA,
                [],
                'missing-1',
            );
        }

        return new DraftResponse(
            $sentences,
            $claims,
            $missingSections,
            ['Model drafting is disabled; deterministic fixture drafting was used.'],
            DraftUsage::fixture(),
        );
    }
}
