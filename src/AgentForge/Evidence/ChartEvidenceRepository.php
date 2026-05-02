<?php

/**
 * Row repository for AgentForge read-only chart evidence tools.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\AgentForge\Evidence;

use OpenEMR\AgentForge\Auth\PatientId;

interface ChartEvidenceRepository
{
    /** @return array<string, mixed>|null */
    public function demographics(PatientId $patientId): ?array;

    /** @return list<array<string, mixed>> */
    public function activeProblems(PatientId $patientId, int $limit): array;

    /** @return list<array<string, mixed>> */
    public function activeMedications(PatientId $patientId, int $limit): array;

    /** @return list<array<string, mixed>> */
    public function recentLabs(PatientId $patientId, int $limit): array;

    /** @return list<array<string, mixed>> */
    public function recentNotes(PatientId $patientId, int $limit): array;
}
