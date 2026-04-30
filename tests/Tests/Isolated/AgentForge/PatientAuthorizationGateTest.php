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

use OpenEMR\AgentForge\AgentQuestion;
use OpenEMR\AgentForge\AgentRequest;
use OpenEMR\AgentForge\PatientAccessRepository;
use OpenEMR\AgentForge\PatientAuthorizationGate;
use OpenEMR\AgentForge\PatientId;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class PatientAuthorizationGateTest extends TestCase
{
    public function testAllowsWhenAllTrustChecksPass(): void
    {
        $decision = $this->gate(patientExists: true, hasRelationship: true)
            ->decide($this->request(), 900001, 7, true);

        $this->assertTrue($decision->allowed);
        $this->assertSame('allowed', $decision->reason);
    }

    public function testRefusesMissingSessionUser(): void
    {
        $decision = $this->gate()->decide($this->request(), 900001, null, true);

        $this->assertFalse($decision->allowed);
        $this->assertSame('No active OpenEMR session user was found.', $decision->reason);
    }

    public function testRefusesMissingPatientContext(): void
    {
        $decision = $this->gate()->decide($this->request(), null, 7, true);

        $this->assertFalse($decision->allowed);
        $this->assertSame('No active patient chart context was found.', $decision->reason);
    }

    public function testRefusesPatientMismatch(): void
    {
        $decision = $this->gate()->decide($this->request(), 42, 7, true);

        $this->assertFalse($decision->allowed);
        $this->assertSame('The requested patient does not match the active chart.', $decision->reason);
    }

    public function testRefusesMissingMedicalAcl(): void
    {
        $decision = $this->gate()->decide($this->request(), 900001, 7, false);

        $this->assertFalse($decision->allowed);
        $this->assertSame('The active user does not have medical-record access.', $decision->reason);
    }

    public function testRefusesUnverifiedPatient(): void
    {
        $decision = $this->gate(patientExists: false)->decide($this->request(), 900001, 7, true);

        $this->assertFalse($decision->allowed);
        $this->assertSame('The requested patient chart could not be verified.', $decision->reason);
    }

    public function testRefusesMissingRelationship(): void
    {
        $decision = $this->gate(patientExists: true, hasRelationship: false)
            ->decide($this->request(), 900001, 7, true);

        $this->assertFalse($decision->allowed);
        $this->assertSame('Patient-specific access could not be verified for this user.', $decision->reason);
    }

    public function testRefusesUnclearRepositoryState(): void
    {
        $decision = $this->gate(throws: true)->decide($this->request(), 900001, 7, true);

        $this->assertFalse($decision->allowed);
        $this->assertSame('Patient-specific access is unclear.', $decision->reason);
    }

    private function request(): AgentRequest
    {
        return new AgentRequest(new PatientId(900001), new AgentQuestion('What changed?'));
    }

    private function gate(
        bool $patientExists = true,
        bool $hasRelationship = true,
        bool $throws = false,
    ): PatientAuthorizationGate {
        $repository = new class ($patientExists, $hasRelationship, $throws) implements PatientAccessRepository {
            public function __construct(
                private readonly bool $patientExists,
                private readonly bool $hasRelationship,
                private readonly bool $throws,
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
        };

        return new PatientAuthorizationGate($repository);
    }
}
