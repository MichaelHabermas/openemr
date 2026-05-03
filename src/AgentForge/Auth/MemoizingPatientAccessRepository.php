<?php

/**
 * Per-request memoizing decorator for patient access lookups.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\AgentForge\Auth;

final class MemoizingPatientAccessRepository implements PatientAccessRepository
{
    /** @var array<int, bool> */
    private array $patientExistsCache = [];

    /** @var array<string, bool> */
    private array $relationshipCache = [];

    public function __construct(private readonly PatientAccessRepository $inner)
    {
    }

    public function patientExists(PatientId $patientId): bool
    {
        return $this->patientExistsCache[$patientId->value]
            ??= $this->inner->patientExists($patientId);
    }

    public function userHasDirectRelationship(PatientId $patientId, int $userId): bool
    {
        $key = $patientId->value . ':' . $userId;

        return $this->relationshipCache[$key]
            ??= $this->inner->userHasDirectRelationship($patientId, $userId);
    }
}
