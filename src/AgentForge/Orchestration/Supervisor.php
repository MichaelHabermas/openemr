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
use OpenEMR\AgentForge\Document\Worker\WorkerName;

final readonly class Supervisor
{
    public function decide(DocumentJob $job, bool $trustedForEvidence): SupervisorDecision
    {
        $context = [
            'job_status' => $job->status->value,
            'doc_type' => $job->docType->value,
            'trusted_for_evidence' => $trustedForEvidence ? 1 : 0,
        ];

        if ($job->retractedAt !== null || $job->status === JobStatus::Retracted) {
            return SupervisorDecision::hold('document_retracted', $context);
        }

        if ($job->status === JobStatus::Failed) {
            return SupervisorDecision::hold('document_processing_failed', $context);
        }

        if ($job->status !== JobStatus::Succeeded) {
            return SupervisorDecision::handoff(WorkerName::IntakeExtractor, 'document_extraction_required', $context);
        }

        if (!$trustedForEvidence) {
            return SupervisorDecision::hold('identity_not_trusted_for_evidence', $context);
        }

        return SupervisorDecision::handoff(WorkerName::EvidenceRetriever, 'trusted_document_ready_for_evidence', $context);
    }
}
