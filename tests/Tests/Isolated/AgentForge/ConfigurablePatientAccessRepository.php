<?php

/**
 * Configurable fake {@see PatientAccessRepository} for isolated AgentForge tests.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Tests\Isolated\AgentForge;

use OpenEMR\AgentForge\Auth\PatientAccessRepository;
use OpenEMR\AgentForge\Auth\PatientId;
use RuntimeException;

final readonly class ConfigurablePatientAccessRepository implements PatientAccessRepository
{
    public function __construct(
        private bool $patientExists = true,
        private bool $hasRelationship = true,
        private bool $throws = false,
    ) {
    }

    public function patientExists(PatientId $patientId): bool
    {
        if ($this->throws) {
            throw new RuntimeException('database unavailable');
        }

        return $this->patientExists;
    }

    public function userHasDirectRelationship(PatientId $patientId, int $userId): bool
    {
        if ($this->throws) {
            throw new RuntimeException('database unavailable');
        }

        return $this->hasRelationship;
    }
}
