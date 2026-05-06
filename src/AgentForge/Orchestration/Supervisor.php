<?php

/**
 * Thin deterministic supervisor/router for AgentForge clinical document jobs.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\AgentForge\Orchestration;

use OpenEMR\AgentForge\Document\DocumentJob;
use OpenEMR\AgentForge\Document\JobStatus;

final readonly class Supervisor
{
    public function decide(DocumentJob $job, bool $trustedForEvidence): SupervisorDecision
    {
        $context = $this->decisionContext($job, $trustedForEvidence);

        if ($job->retractedAt !== null || $job->status === JobStatus::Retracted) {
            return $this->holdDecision('document_retracted', $context);
        }

        if ($job->status === JobStatus::Failed) {
            return $this->holdDecision('document_processing_failed', $context);
        }

        if ($job->status !== JobStatus::Succeeded) {
            return $this->handoffDecision(NodeName::IntakeExtractor, 'document_extraction_required', $context);
        }

        if (!$trustedForEvidence) {
            return $this->holdDecision('identity_not_trusted_for_evidence', $context);
        }

        return $this->handoffDecision(NodeName::EvidenceRetriever, 'trusted_document_ready_for_evidence', $context);
    }

    /**
     * @return array<string, scalar|null>
     */
    private function decisionContext(DocumentJob $job, bool $trustedForEvidence): array
    {
        return [
            'job_status' => $job->status->value,
            'doc_type' => $job->docType->value,
            'trusted_for_evidence' => $trustedForEvidence ? 1 : 0,
        ];
    }

    /**
     * @param array<string, scalar|null> $context
     */
    private function holdDecision(string $reason, array $context): SupervisorDecision
    {
        return SupervisorDecision::hold($reason, $context);
    }

    /**
     * @param array<string, scalar|null> $context
     */
    private function handoffDecision(NodeName $targetNode, string $reason, array $context): SupervisorDecision
    {
        return SupervisorDecision::handoff($targetNode, $reason, $context);
    }
}
