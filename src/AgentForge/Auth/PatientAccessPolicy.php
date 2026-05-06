<?php

/**
 * Fail-closed patient access policy with stable decision codes.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\AgentForge\Auth;

use RuntimeException;

final readonly class PatientAccessPolicy
{
    public function __construct(private PatientAccessRepository $repository)
    {
    }

    public function decide(
        PatientId $patientId,
        ?int $sessionPatientId,
        ?int $sessionUserId,
        bool $hasMedicalRecordAcl,
    ): AuthorizationDecision {
        if ($sessionUserId === null || $sessionUserId <= 0) {
            return AuthorizationDecision::refuse(
                'No active OpenEMR session user was found.',
                'no_active_openemr_session_user_was_found',
            );
        }

        if ($sessionPatientId === null || $sessionPatientId <= 0) {
            return AuthorizationDecision::refuse(
                'No active patient chart context was found.',
                'no_active_patient_chart_context_was_found',
            );
        }

        if ($patientId->value !== $sessionPatientId) {
            return AuthorizationDecision::refuse(
                'The requested patient does not match the active chart.',
                'the_requested_patient_does_not_match_the_active_chart',
            );
        }

        if (!$hasMedicalRecordAcl) {
            return AuthorizationDecision::refuse(
                'The active user does not have medical-record access.',
                'the_active_user_does_not_have_medical_record_access',
            );
        }

        try {
            if (!$this->repository->patientExists($patientId)) {
                return AuthorizationDecision::refuse(
                    'The requested patient chart could not be verified.',
                    'the_requested_patient_chart_could_not_be_verified',
                );
            }

            if (!$this->repository->userHasDirectRelationship($patientId, $sessionUserId)) {
                return AuthorizationDecision::refuse(
                    'Patient-specific access could not be verified for this user.',
                    'patient_specific_access_could_not_be_verified_for_this_user',
                );
            }
        } catch (RuntimeException) {
            return AuthorizationDecision::refuse(
                'Patient-specific access is unclear.',
                'patient_specific_access_is_unclear',
            );
        }

        return AuthorizationDecision::allow();
    }
}
