<?php

/**
 * Runtime coordinator for supervisor operations.
 *
 * Combines the Supervisor (policy + logging) with context building
 * for document jobs and agent requests.
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

final readonly class SupervisorRuntime
{
    public function __construct(
        private Supervisor $supervisor,
    ) {
    }

    /**
     * Route a document job through the supervisor.
     */
    public function routeDocumentJob(
        DocumentJob $job,
        PatientId $patientId,
        bool $trustedForEvidence,
        int $deadlineMs = 60000,
    ): HandoffDecision {
        $context = HandoffContext::forDocument($job, $patientId, $trustedForEvidence, $deadlineMs);

        return $this->supervisor->route($context);
    }

    /**
     * Route a chat/agent request through the supervisor.
     */
    public function routeChatRequest(
        AgentQuestion $question,
        PatientId $patientId,
        string $questionType = 'general',
        int $deadlineMs = 20000,
        ?string $conversationSummary = null,
    ): HandoffDecision {
        $context = HandoffContext::forChat($question, $patientId, $questionType, $deadlineMs, $conversationSummary);

        return $this->supervisor->route($context);
    }
}
