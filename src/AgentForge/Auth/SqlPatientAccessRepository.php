<?php

/**
 * SQL-backed patient relationship lookups for AgentForge authorization.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\AgentForge\Auth;

use OpenEMR\Common\Database\QueryUtils;

final class SqlPatientAccessRepository implements PatientAccessRepository
{
    public function patientExists(PatientId $patientId): bool
    {
        $pid = QueryUtils::fetchSingleValue(
            'SELECT pid FROM patient_data WHERE pid = ? LIMIT 1',
            'pid',
            [$patientId->value],
        );

        return $pid !== null;
    }

    public function userHasDirectRelationship(PatientId $patientId, int $userId): bool
    {
        $primaryProviderPid = QueryUtils::fetchSingleValue(
            'SELECT pid FROM patient_data WHERE pid = ? AND providerID = ? LIMIT 1',
            'pid',
            [$patientId->value, $userId],
        );
        if ($primaryProviderPid !== null) {
            return true;
        }

        $encounterPid = QueryUtils::fetchSingleValue(
            'SELECT pid FROM form_encounter WHERE pid = ? AND (provider_id = ? OR supervisor_id = ?) LIMIT 1',
            'pid',
            [$patientId->value, $userId, $userId],
        );

        return $encounterPid !== null;
    }
}
