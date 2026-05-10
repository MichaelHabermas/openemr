<?php

/**
 * Narrow pipeline for drafting and verification only.
 *
 * Single responsibility: generate draft and verify against evidence.
 * No response shaping, no fallback handling, no telemetry assembly.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\AgentForge\ResponseGeneration;

use OpenEMR\AgentForge\Deadline;
use OpenEMR\AgentForge\Evidence\EvidenceBundle;
use OpenEMR\AgentForge\Time\MonotonicClock;
use OpenEMR\AgentForge\Verification\DraftVerifier;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

final readonly class DraftVerificationPipeline
{
    public function __construct(
        private DraftProvider $draftProvider,
        private DraftVerifier $verifier,
        private MonotonicClock $clock,
        private ?LoggerInterface $logger = null,
    ) {
    }

    /**
     * Draft and verify in a single operation.
     *
     * @param DraftRequest $request The drafting request
     * @param EvidenceBundle $bundle The evidence to draft from
     * @param Deadline $deadline Time constraint for drafting
     * @return DraftVerificationPair The draft with verification result
     * @throws DraftProviderException If drafting fails
     */
    public function draftAndVerify(
        DraftRequest $request,
        EvidenceBundle $bundle,
        Deadline $deadline,
    ): DraftVerificationPair {
        $logger = $this->logger ?? new NullLogger();

        // Generate draft with retry
        $draft = $this->draftWithRetry($request, $bundle, $deadline);

        // Verify against evidence
        $result = $this->verifier->verify($draft, $bundle);

        $logger->debug('Draft verification complete', [
            'verified' => $result->verified,
            'claims_total' => count($draft->claims),
        ]);

        return new DraftVerificationPair($draft, $result);
    }

    /**
     * Draft with single retry on failure.
     *
     * @throws DraftProviderException If both attempts fail
     */
    private function draftWithRetry(
        DraftRequest $request,
        EvidenceBundle $bundle,
        Deadline $deadline,
    ): DraftResponse {
        try {
            return $this->draftProvider->generateDraft($request, $bundle, $deadline);
        } catch (DraftProviderException $firstException) {
            // Retry once
            try {
                return $this->draftProvider->generateDraft($request, $bundle, $deadline);
            } catch (DraftProviderException) {
                throw $firstException; // Throw original error
            }
        }
    }
}
