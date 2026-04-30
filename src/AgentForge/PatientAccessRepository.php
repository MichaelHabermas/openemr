<?php

/**
 * Patient relationship lookup boundary for AgentForge authorization.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\AgentForge;

interface PatientAccessRepository
{
    public function patientExists(PatientId $patientId): bool;

    public function userHasDirectRelationship(PatientId $patientId, int $userId): bool;
}
