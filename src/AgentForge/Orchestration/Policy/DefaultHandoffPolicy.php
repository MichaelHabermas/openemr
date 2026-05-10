<?php

/**
 * Default handoff policy for chat/agent requests.
 *
 * Encapsulates current heuristic-based routing logic:
 * - Keyword detection for guideline evidence
 * - Question type matching
 * - Refusal triggers (cross-patient, unsafe advice)
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\AgentForge\Orchestration\Policy;

use OpenEMR\AgentForge\Orchestration\HandoffContext;
use OpenEMR\AgentForge\Orchestration\HandoffDecision;
use OpenEMR\AgentForge\Orchestration\HandoffPolicy;

final readonly class DefaultHandoffPolicy implements HandoffPolicy
{
    public function decide(HandoffContext $context): HandoffDecision
    {
        // Check for cross-patient scope violations
        if ($context->isCrossPatientQuery()) {
            return HandoffDecision::refuse('cross_patient_scope', [
                'question_preview' => $this->preview($context->question->value),
            ]);
        }

        // Check for unsafe advice requests
        if ($context->requiresUnsafeRefusal()) {
            return HandoffDecision::refuse('unsafe_advice_request', [
                'question_preview' => $this->preview($context->question->value),
            ]);
        }

        // Check for guideline evidence needs
        if ($context->requiresGuidelineEvidence()) {
            return HandoffDecision::guideline('guideline_evidence_required', [
                'question_type' => $context->questionType,
                'detected_keywords' => $this->detectedGuidelineKeywords($context->question->value),
            ]);
        }

        // Default: extraction for document-related queries
        if ($context->isDocumentJob()) {
            return $this->decideForDocumentJob($context);
        }

        // Standard chat: direct answer (no worker needed)
        return HandoffDecision::answer('direct_answer', [
            'question_type' => $context->questionType,
        ]);
    }

    /**
     * Decision logic for document jobs.
     */
    private function decideForDocumentJob(HandoffContext $context): HandoffDecision
    {
        $job = $context->documentJob;
        if ($job === null) {
            return HandoffDecision::hold('no_document_job', []);
        }

        // Check job status
        if ($job->retractedAt !== null) {
            return HandoffDecision::hold('document_retracted', [
                'job_id' => $job->id ?? null,
            ]);
        }

        if ($job->status->value === 'failed') {
            return HandoffDecision::hold('document_processing_failed', [
                'job_id' => $job->id ?? null,
            ]);
        }

        if ($job->status->value !== 'succeeded') {
            return HandoffDecision::extract('document_extraction_required', [
                'job_id' => $job->id ?? null,
                'job_status' => $job->status->value,
            ]);
        }

        // Job succeeded, check trust
        $trusted = $context->metadata['trusted_for_evidence'] ?? false;
        if (!$trusted) {
            return HandoffDecision::hold('identity_not_trusted_for_evidence', [
                'job_id' => $job->id ?? null,
            ]);
        }

        return HandoffDecision::guideline('trusted_document_ready_for_evidence', [
            'job_id' => $job->id ?? null,
            'document_id' => $job->documentId ?? null,
        ]);
    }

    /**
     * Create a safe preview of the question for logging.
     */
    private function preview(string $question): string
    {
        $preview = substr($question, 0, 50);

        return strlen($question) > 50 ? $preview . '...' : $preview;
    }

    /**
     * Detect which guideline keywords triggered the decision.
     *
     * @return list<string>
     */
    private function detectedGuidelineKeywords(string $question): array
    {
        $normalized = strtolower($question);
        $keywords = [];
        $detectable = ['guideline', 'evidence', 'acc/aha', 'acc aha', 'ada', 'uspstf', 'what changed', 'deserves attention', 'pay attention'];

        foreach ($detectable as $keyword) {
            if (str_contains($normalized, $keyword)) {
                $keywords[] = $keyword;
            }
        }

        return $keywords;
    }
}
