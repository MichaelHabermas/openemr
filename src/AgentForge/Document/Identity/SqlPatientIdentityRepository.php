<?php

/**
 * SQL-backed OpenEMR patient identity repository.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\AgentForge\Document\Identity;

use OpenEMR\AgentForge\Auth\PatientId;
use OpenEMR\AgentForge\DatabaseExecutor;
use OpenEMR\AgentForge\RowHydrator;

final readonly class SqlPatientIdentityRepository implements PatientIdentityRepository
{
    public function __construct(private DatabaseExecutor $executor)
    {
    }

    public function findByPatientId(PatientId $patientId): ?PatientIdentity
    {
        $records = $this->executor->fetchRecords(
            'SELECT pid, fname, lname, DOB, pubpid FROM patient_data WHERE pid = ? LIMIT 1',
            [$patientId->value],
        );

        if ($records === []) {
            return null;
        }

        $record = $records[0];

        return new PatientIdentity(
            new PatientId(RowHydrator::intValue($record['pid'] ?? null, 'pid')),
            RowHydrator::stringValue($record['fname'] ?? null, 'fname'),
            RowHydrator::stringValue($record['lname'] ?? null, 'lname'),
            self::nullableNonEmptyString($record['DOB'] ?? null, 'DOB'),
            self::nullableNonEmptyString($record['pubpid'] ?? null, 'pubpid'),
        );
    }

    private static function nullableNonEmptyString(mixed $value, string $field): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return RowHydrator::stringValue($value, $field);
    }
}
