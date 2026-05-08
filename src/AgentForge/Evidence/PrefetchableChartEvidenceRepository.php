<?php

/**
 * Optional repository hook that lets a chart evidence collector
 * coalesce per-section reads into a single coordinated batch before tools run.
 *
 * Implementations may use the planner's section list to issue one combined
 * query (UNION ALL, multi-statement, or async fan-out) and warm an internal
 * cache that the per-section repository methods then read from. Repositories
 * that don't implement this interface still work with the concurrent collector;
 * they just don't get the prefetch coordination win.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\AgentForge\Evidence;

use OpenEMR\AgentForge\Auth\PatientId;
use OpenEMR\AgentForge\Deadline;

interface PrefetchableChartEvidenceRepository extends ChartEvidenceRepository
{
    /**
     * Warm a coordinated read for the given planner sections. Subsequent calls
     * to per-section repository methods MUST return the prefetched data when
     * the same patient and sections are requested within this collect cycle.
     *
     * @param list<string> $sections
     */
    public function prefetch(PatientId $patientId, array $sections, ?Deadline $deadline = null): void;
}
