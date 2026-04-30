<?php

/**
 * Fail-closed patient authorization gate for AgentForge.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\AgentForge;

use Throwable;

final readonly class PatientAuthorizationGate
{
    public function __construct(private PatientAccessRepository $repository)
    {
    }

    public function decide(
        AgentRequest $request,
        ?int $sessionPatientId,
        ?int $sessionUserId,
        bool $hasMedicalRecordAcl,
    ): AuthorizationDecision {
        if ($sessionUserId === null || $sessionUserId <= 0) {
            return AuthorizationDecision::refuse('No active OpenEMR session user was found.');
        }

        if ($sessionPatientId === null || $sessionPatientId <= 0) {
            return AuthorizationDecision::refuse('No active patient chart context was found.');
        }

        if ($request->patientId->value !== $sessionPatientId) {
            return AuthorizationDecision::refuse('The requested patient does not match the active chart.');
        }

        if (!$hasMedicalRecordAcl) {
            return AuthorizationDecision::refuse('The active user does not have medical-record access.');
        }

        try {
            if (!$this->repository->patientExists($request->patientId)) {
                return AuthorizationDecision::refuse('The requested patient chart could not be verified.');
            }

            if (!$this->repository->userHasDirectRelationship($request->patientId, $sessionUserId)) {
                return AuthorizationDecision::refuse('Patient-specific access could not be verified for this user.');
            }
        } catch (Throwable) {
            return AuthorizationDecision::refuse('Patient-specific access is unclear.');
        }

        return AuthorizationDecision::allow();
    }
}
