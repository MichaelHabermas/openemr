<?php

/**
 * Strategy contract for collecting chart evidence for the active patient.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\AgentForge\Evidence;

use OpenEMR\AgentForge\Auth\PatientId;
use OpenEMR\AgentForge\Deadline;
use OpenEMR\AgentForge\Observability\StageTimer;

interface ChartEvidenceCollector
{
    public function collect(
        PatientId $patientId,
        ChartQuestionPlan $plan,
        ?StageTimer $timer = null,
        ?Deadline $deadline = null,
    ): EvidenceRun;
}
