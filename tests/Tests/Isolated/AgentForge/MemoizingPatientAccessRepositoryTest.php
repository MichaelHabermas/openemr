<?php

/**
 * Isolated tests for AgentForge memoizing patient access decorator.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Tests\Isolated\AgentForge;

use OpenEMR\AgentForge\Auth\MemoizingPatientAccessRepository;
use OpenEMR\AgentForge\Auth\PatientAccessRepository;
use OpenEMR\AgentForge\Auth\PatientId;
use PHPUnit\Framework\TestCase;

final class MemoizingPatientAccessRepositoryTest extends TestCase
{
    public function testPatientExistsCallsInnerOncePerPatient(): void
    {
        $inner = new CountingPatientAccessRepository(patientExists: true, hasRelationship: false);
        $repository = new MemoizingPatientAccessRepository($inner);
        $patientId = new PatientId(900001);

        $this->assertTrue($repository->patientExists($patientId));
        $this->assertTrue($repository->patientExists($patientId));
        $this->assertTrue($repository->patientExists($patientId));

        $this->assertSame(1, $inner->patientExistsCalls);
    }

    public function testPatientExistsCachesPerPatientId(): void
    {
        $inner = new CountingPatientAccessRepository(patientExists: true, hasRelationship: false);
        $repository = new MemoizingPatientAccessRepository($inner);

        $repository->patientExists(new PatientId(900001));
        $repository->patientExists(new PatientId(900002));
        $repository->patientExists(new PatientId(900001));

        $this->assertSame(2, $inner->patientExistsCalls);
    }

    public function testRelationshipCallsInnerOncePerUserAndPatient(): void
    {
        $inner = new CountingPatientAccessRepository(patientExists: false, hasRelationship: true);
        $repository = new MemoizingPatientAccessRepository($inner);
        $patientId = new PatientId(900001);

        $this->assertTrue($repository->userHasDirectRelationship($patientId, 7));
        $this->assertTrue($repository->userHasDirectRelationship($patientId, 7));
        $this->assertTrue($repository->userHasDirectRelationship($patientId, 8));

        $this->assertSame(2, $inner->relationshipCalls);
    }

    public function testRelationshipCacheIsScopedByPatientId(): void
    {
        $inner = new CountingPatientAccessRepository(patientExists: false, hasRelationship: true);
        $repository = new MemoizingPatientAccessRepository($inner);

        $repository->userHasDirectRelationship(new PatientId(900001), 7);
        $repository->userHasDirectRelationship(new PatientId(900002), 7);
        $repository->userHasDirectRelationship(new PatientId(900001), 7);

        $this->assertSame(2, $inner->relationshipCalls);
    }
}

final class CountingPatientAccessRepository implements PatientAccessRepository
{
    public int $patientExistsCalls = 0;

    public int $relationshipCalls = 0;

    public function __construct(
        private readonly bool $patientExists,
        private readonly bool $hasRelationship,
    ) {
    }

    public function patientExists(PatientId $patientId): bool
    {
        $this->patientExistsCalls++;

        return $this->patientExists;
    }

    public function userHasDirectRelationship(PatientId $patientId, int $userId): bool
    {
        $this->relationshipCalls++;

        return $this->hasRelationship;
    }
}
