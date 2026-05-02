<?php

/**
 * Scenario-driven patient access policy for deterministic AgentForge evals.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\AgentForge\Eval;

use OpenEMR\AgentForge\Auth\PatientAccessRepository;
use OpenEMR\AgentForge\Auth\PatientId;

final readonly class EvalPatientAccessRepository implements PatientAccessRepository
{
    public function __construct(private string $scenario)
    {
    }

    public function patientExists(PatientId $patientId): bool
    {
        return in_array($patientId->value, [900001, 900002, 900003, 42], true);
    }

    public function userHasDirectRelationship(PatientId $patientId, int $userId): bool
    {
        return $this->scenario !== 'unauthorized'
            && in_array($patientId->value, [900001, 900002, 900003], true)
            && $userId === 7;
    }
}
