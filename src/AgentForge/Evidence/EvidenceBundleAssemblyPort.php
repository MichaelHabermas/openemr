<?php

/**
 * Port interface for evidence bundle assembly strategies.
 *
 * Implementations encapsulate different collection approaches:
 * - Serial evidence collection (one tool at a time)
 * - Concurrent collection (parallel tool execution)
 * - On-demand document re-extraction
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\AgentForge\Evidence;

use OpenEMR\AgentForge\Auth\PatientId;
use OpenEMR\AgentForge\Deadline;
use OpenEMR\AgentForge\Handlers\AgentQuestion;

interface EvidenceBundleAssemblyPort
{
    /**
     * Assemble an evidence bundle for a patient question.
     *
     * @param PatientId $patientId The patient to collect evidence for
     * @param AgentQuestion $question The clinical question being asked
     * @param ChartQuestionPlan $plan The planned evidence sections to collect
     * @param ?Deadline $deadline Optional time constraint for collection
     * @return EvidenceRun The collected evidence with timing and coverage info
     */
    public function assembleBundle(
        PatientId $patientId,
        AgentQuestion $question,
        ChartQuestionPlan $plan,
        ?Deadline $deadline = null,
    ): EvidenceRun;
}
