<?php

/**
 * SQL-backed patient relationship lookups for AgentForge authorization.
 *
 * A user is treated as having a direct chart relationship when any of:
 * - the patient lists them as primary provider (`patient_data.providerID`);
 * - an encounter lists them as rendering or supervising provider;
 * - they are an active member of an active care team for the patient.
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

        if ($encounterPid !== null) {
            return true;
        }

        $careTeamMemberId = QueryUtils::fetchSingleValue(
            'SELECT ctm.id FROM care_team_member AS ctm INNER JOIN care_teams AS ct ON ct.id = ctm.care_team_id' .
            ' WHERE ct.pid = ? AND ctm.user_id = ? AND ct.status = ? AND ctm.status = ? LIMIT 1',
            'id',
            [$patientId->value, $userId, 'active', 'active'],
        );

        return $careTeamMemberId !== null;
    }
}
