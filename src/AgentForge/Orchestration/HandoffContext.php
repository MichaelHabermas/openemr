<?php

/**
 * Immutable context for handoff policy decisions.
 *
 * Encapsulates all inputs needed for routing decisions:
 * - Request type (chat vs document job)
 * - Patient context
 * - Conversation state
 * - Document job state (if applicable)
 * - Deadline/time constraints
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\AgentForge\Orchestration;

use OpenEMR\AgentForge\Auth\PatientId;
use OpenEMR\AgentForge\Document\DocumentJob;
use OpenEMR\AgentForge\Handlers\AgentQuestion;

final readonly class HandoffContext
{
    /**
     * @param AgentQuestion $question The user's question/request
     * @param PatientId $patientId The patient context
     * @param string $questionType Classification of question (e.g., 'medication_review')
     * @param int $deadlineRemainingMs Milliseconds remaining before deadline
     * @param ?DocumentJob $documentJob Active document job if applicable
     * @param ?string $conversationSummary Previous conversation context
     * @param array<string, scalar|null> $metadata Additional routing metadata
     */
    public function __construct(
        public AgentQuestion $question,
        public PatientId $patientId,
        public string $questionType = 'general',
        public int $deadlineRemainingMs = 20000,
        public ?DocumentJob $documentJob = null,
        public ?string $conversationSummary = null,
        public array $metadata = [],
    ) {
    }

    /**
     * Create context for a chat/agent request.
     */
    public static function forChat(
        AgentQuestion $question,
        PatientId $patientId,
        string $questionType = 'general',
        int $deadlineMs = 20000,
        ?string $conversationSummary = null,
    ): self {
        return new self(
            question: $question,
            patientId: $patientId,
            questionType: $questionType,
            deadlineRemainingMs: $deadlineMs,
            documentJob: null,
            conversationSummary: $conversationSummary,
        );
    }

    /**
     * Create context for a document job.
     */
    public static function forDocument(
        DocumentJob $job,
        PatientId $patientId,
        bool $trustedForEvidence,
        int $deadlineMs = 60000,
    ): self {
        return new self(
            question: new AgentQuestion('document_processing'),
            patientId: $patientId,
            questionType: 'document_job',
            deadlineRemainingMs: $deadlineMs,
            documentJob: $job,
            metadata: ['trusted_for_evidence' => $trustedForEvidence],
        );
    }

    /**
     * Check if this context represents a document job.
     */
    public function isDocumentJob(): bool
    {
        return $this->documentJob !== null;
    }

    /**
     * Check if guideline evidence is explicitly requested.
     */
    public function requiresGuidelineEvidence(): bool
    {
        $normalized = strtolower($this->question->value);

        return str_contains($normalized, 'guideline')
            || str_contains($normalized, 'evidence')
            || str_contains($normalized, 'acc/aha')
            || str_contains($normalized, 'acc aha')
            || str_contains($normalized, 'ada')
            || str_contains($normalized, 'uspstf')
            || str_contains($normalized, 'what changed')
            || str_contains($normalized, 'deserves attention')
            || str_contains($normalized, 'pay attention')
            || in_array($this->questionType, ['follow_up_change_review', 'pre_prescribing_chart_check'], true);
    }

    /**
     * Check if this is a cross-patient scope violation.
     */
    public function isCrossPatientQuery(): bool
    {
        $normalized = strtolower($this->question->value);

        return str_contains($normalized, 'other patient')
            || str_contains($normalized, 'compare to')
            || str_contains($normalized, 'patient in room')
            || str_contains($normalized, 'not this patient');
    }

    /**
     * Check if this requires unsafe advice refusal.
     */
    public function requiresUnsafeRefusal(): bool
    {
        $normalized = strtolower($this->question->value);

        return str_contains($normalized, 'double')
            || str_contains($normalized, 'stop taking')
            || str_contains($normalized, 'ignore')
            || str_contains($normalized, 'unsafe');
    }
}
