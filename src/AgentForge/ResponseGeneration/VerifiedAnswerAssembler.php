<?php

/**
 * Port interface for assembling verified responses into final AgentResponse.
 *
 * Implementations handle:
 * - Citation formatting
 * - Section layout per question type
 * - Missing evidence messaging
 * - Refusal formatting
 * - Follow-up special cases
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\AgentForge\ResponseGeneration;

use OpenEMR\AgentForge\Evidence\EvidenceBundle;
use OpenEMR\AgentForge\Handlers\AgentResponse;
use OpenEMR\AgentForge\Verification\VerificationResult;

interface VerifiedAnswerAssembler
{
    /**
     * Assemble a verified answer from draft and verification.
     *
     * @param DraftResponse $draft The drafted response
     * @param VerificationResult $result Verification result
     * @param EvidenceBundle $bundle Source evidence for citations
     * @param string $questionType Classification of question (affects sections)
     * @return AgentResponse Final assembled response
     */
    public function assembleVerified(
        DraftResponse $draft,
        VerificationResult $result,
        EvidenceBundle $bundle,
        string $questionType,
    ): AgentResponse;

    /**
     * Assemble a refusal response.
     *
     * @param string $reason Machine-readable refusal reason
     * @param EvidenceBundle $bundle Evidence that was available (may be empty)
     * @param string $questionType Classification of question
     * @param ?string $explanation Optional human-readable explanation
     * @return AgentResponse Refusal response
     */
    public function assembleRefusal(
        string $reason,
        EvidenceBundle $bundle,
        string $questionType,
        ?string $explanation = null,
    ): AgentResponse;

    /**
     * Assemble response for known missing data scenario.
     *
     * @param EvidenceBundle $bundle Partial evidence that was found
     * @param list<string> $missingSections Sections that were missing
     * @param string $questionType Classification of question
     * @return AgentResponse Response acknowledging gaps
     */
    public function assembleKnownMissing(
        EvidenceBundle $bundle,
        array $missingSections,
        string $questionType,
    ): AgentResponse;

    /**
     * Assemble response when draft provider is unavailable.
     *
     * @param string $reason Error reason (e.g., 'provider_unavailable')
     * @param EvidenceBundle $bundle Available evidence
     * @param string $questionType Classification of question
     * @return AgentResponse Fallback response
     */
    public function assembleProviderUnavailable(
        string $reason,
        EvidenceBundle $bundle,
        string $questionType,
    ): AgentResponse;
}
