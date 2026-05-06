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

use InvalidArgumentException;
use OpenEMR\AgentForge\Auth\PatientId;
use OpenEMR\AgentForge\DatabaseExecutor;
use OpenEMR\AgentForge\DefaultDatabaseExecutor;

final readonly class SqlPatientIdentityRepository implements PatientIdentityRepository
{
    private DatabaseExecutor $executor;

    public function __construct(?DatabaseExecutor $executor = null)
    {
        $this->executor = $executor ?? new DefaultDatabaseExecutor();
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
            new PatientId($this->intValue($record['pid'] ?? null, 'pid')),
            $this->stringValue($record['fname'] ?? null, 'fname'),
            $this->stringValue($record['lname'] ?? null, 'lname'),
            $this->optionalString($record['DOB'] ?? null),
            $this->optionalString($record['pubpid'] ?? null),
        );
    }

    private function intValue(mixed $value, string $field): int
    {
        if (!is_scalar($value)) {
            throw new InvalidArgumentException("Expected scalar {$field}.");
        }

        return (int) $value;
    }

    private function stringValue(mixed $value, string $field): string
    {
        if (!is_scalar($value)) {
            throw new InvalidArgumentException("Expected scalar {$field}.");
        }

        return (string) $value;
    }

    private function optionalString(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (!is_scalar($value)) {
            throw new InvalidArgumentException('Expected nullable scalar.');
        }

        return (string) $value;
    }
}
