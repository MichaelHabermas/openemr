<?php

/**
 * Chart-side patient identity used by AgentForge document verification.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\AgentForge\Document\Identity;

use DomainException;
use OpenEMR\AgentForge\Auth\PatientId;

final readonly class PatientIdentity
{
    public function __construct(
        public PatientId $patientId,
        public string $firstName,
        public string $lastName,
        public ?string $dateOfBirth,
        public ?string $medicalRecordNumber = null,
    ) {
        if (trim($firstName) === '') {
            throw new DomainException('Patient first name must be present.');
        }

        if (trim($lastName) === '') {
            throw new DomainException('Patient last name must be present.');
        }

        if ($dateOfBirth !== null && $dateOfBirth !== '' && !self::isDateOfBirth($dateOfBirth)) {
            throw new DomainException('Patient date of birth must use YYYY-MM-DD.');
        }

        if ($medicalRecordNumber !== null && trim($medicalRecordNumber) === '') {
            throw new DomainException('Patient MRN must be null or non-empty.');
        }
    }

    private static function isDateOfBirth(string $dateOfBirth): bool
    {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateOfBirth)) {
            return false;
        }

        [$year, $month, $day] = array_map('intval', explode('-', $dateOfBirth));

        return checkdate($month, $day, $year);
    }
}
