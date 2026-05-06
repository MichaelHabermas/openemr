<?php

/**
 * Isolated tests for AgentForge patient authorization.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Tests\Isolated\AgentForge;

use OpenEMR\AgentForge\Auth\PatientAuthorizationGate;
use OpenEMR\AgentForge\Auth\PatientId;
use PHPUnit\Framework\TestCase;

final class PatientAuthorizationGateTest extends TestCase
{
    public function testAllowsWhenAllTrustChecksPass(): void
    {
        $decision = $this->gate(patientExists: true, hasRelationship: true)
            ->decide($this->patientId(), 900001, 7, true);

        $this->assertTrue($decision->allowed);
        $this->assertSame('allowed', $decision->reason);
        $this->assertSame('allowed', $decision->code);
    }

    public function testRefusesMissingSessionUser(): void
    {
        $decision = $this->gate()->decide($this->patientId(), 900001, null, true);

        $this->assertFalse($decision->allowed);
        $this->assertSame('No active OpenEMR session user was found.', $decision->reason);
        $this->assertSame('no_active_openemr_session_user_was_found', $decision->code);
    }

    public function testRefusesMissingPatientContext(): void
    {
        $decision = $this->gate()->decide($this->patientId(), null, 7, true);

        $this->assertFalse($decision->allowed);
        $this->assertSame('No active patient chart context was found.', $decision->reason);
        $this->assertSame('no_active_patient_chart_context_was_found', $decision->code);
    }

    public function testRefusesPatientMismatch(): void
    {
        $decision = $this->gate()->decide($this->patientId(), 42, 7, true);

        $this->assertFalse($decision->allowed);
        $this->assertSame('The requested patient does not match the active chart.', $decision->reason);
        $this->assertSame('the_requested_patient_does_not_match_the_active_chart', $decision->code);
    }

    public function testRefusesMissingMedicalAcl(): void
    {
        $decision = $this->gate()->decide($this->patientId(), 900001, 7, false);

        $this->assertFalse($decision->allowed);
        $this->assertSame('The active user does not have medical-record access.', $decision->reason);
        $this->assertSame('the_active_user_does_not_have_medical_record_access', $decision->code);
    }

    public function testRefusesUnverifiedPatient(): void
    {
        $decision = $this->gate(patientExists: false)->decide($this->patientId(), 900001, 7, true);

        $this->assertFalse($decision->allowed);
        $this->assertSame('The requested patient chart could not be verified.', $decision->reason);
        $this->assertSame('the_requested_patient_chart_could_not_be_verified', $decision->code);
    }

    public function testRefusesMissingRelationship(): void
    {
        $decision = $this->gate(patientExists: true, hasRelationship: false)
            ->decide($this->patientId(), 900001, 7, true);

        $this->assertFalse($decision->allowed);
        $this->assertSame('Patient-specific access could not be verified for this user.', $decision->reason);
        $this->assertSame('patient_specific_access_could_not_be_verified_for_this_user', $decision->code);
    }

    public function testUnsupportedExpandedRelationshipShapesRemainFailClosed(): void
    {
        $decision = $this->gate(patientExists: true, hasRelationship: false)
            ->decide($this->patientId(), 900001, 7, true);

        $this->assertFalse($decision->allowed);
        $this->assertSame(
            'Patient-specific access could not be verified for this user.',
            $decision->reason,
        );
        $this->assertSame('patient_specific_access_could_not_be_verified_for_this_user', $decision->code);
    }

    public function testRefusesUnclearRepositoryState(): void
    {
        $decision = $this->gate(throws: true)->decide($this->patientId(), 900001, 7, true);

        $this->assertFalse($decision->allowed);
        $this->assertSame('Patient-specific access is unclear.', $decision->reason);
        $this->assertSame('patient_specific_access_is_unclear', $decision->code);
    }

    private function patientId(): PatientId
    {
        return new PatientId(900001);
    }

    private function gate(
        bool $patientExists = true,
        bool $hasRelationship = true,
        bool $throws = false,
    ): PatientAuthorizationGate {
        return new PatientAuthorizationGate(new ConfigurablePatientAccessRepository($patientExists, $hasRelationship, $throws));
    }
}
