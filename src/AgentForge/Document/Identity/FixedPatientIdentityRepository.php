<?php

/**
 * Deterministic patient identity repository for tests and eval adapters.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\AgentForge\Document\Identity;

use OpenEMR\AgentForge\Auth\PatientId;

final readonly class FixedPatientIdentityRepository implements PatientIdentityRepository
{
    public function __construct(private PatientIdentity $identity)
    {
    }

    public function findByPatientId(PatientId $patientId): ?PatientIdentity
    {
        if ($patientId->value !== $this->identity->patientId->value) {
            return null;
        }

        return $this->identity;
    }
}
