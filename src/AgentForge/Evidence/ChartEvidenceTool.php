<?php

/**
 * Read-only evidence tool contract for one active patient chart.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\AgentForge\Evidence;

use OpenEMR\AgentForge\Auth\PatientId;
use OpenEMR\AgentForge\Deadline;

interface ChartEvidenceTool
{
    public function section(): string;

    public function collect(PatientId $patientId, ?Deadline $deadline = null): EvidenceResult;
}
