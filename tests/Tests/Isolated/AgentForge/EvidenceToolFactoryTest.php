<?php

/**
 * Isolated tests for AgentForge evidence tool composition.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Tests\Isolated\AgentForge;

use OpenEMR\AgentForge\Auth\PatientId;
use OpenEMR\AgentForge\Deadline;
use OpenEMR\AgentForge\Evidence\ChartEvidenceRepository;
use OpenEMR\AgentForge\Evidence\EvidenceToolFactory;
use PHPUnit\Framework\TestCase;

final class EvidenceToolFactoryTest extends TestCase
{
    public function testDefaultFactoryBuildsEveryEpicFiveEvidenceTool(): void
    {
        $tools = EvidenceToolFactory::createDefault($this->emptyRepository());

        $this->assertSame(
            [
                'Demographics',
                'Recent encounters',
                'Active problems',
                'Active medications',
                'Inactive medication history',
                'Allergies',
                'Recent labs',
                'Recent clinical documents',
                'Recent vitals',
                'Last-known stale vitals',
                'Recent notes and last plan',
            ],
            array_map(static fn ($tool): string => $tool->section(), $tools),
        );
    }

    private function emptyRepository(): ChartEvidenceRepository
    {
        return new class implements ChartEvidenceRepository {
            public function demographics(PatientId $patientId, ?Deadline $deadline = null): ?array
            {
                return null;
            }

            public function activeProblems(PatientId $patientId, int $limit, ?Deadline $deadline = null): array
            {
                return [];
            }

            public function activeMedications(PatientId $patientId, int $limit, ?Deadline $deadline = null): array
            {
                return [];
            }

            public function inactiveMedications(PatientId $patientId, int $limit, ?Deadline $deadline = null): array
            {
                return [];
            }

            public function activeAllergies(PatientId $patientId, int $limit, ?Deadline $deadline = null): array
            {
                return [];
            }

            public function recentLabs(PatientId $patientId, int $limit, ?Deadline $deadline = null): array
            {
                return [];
            }

            public function recentVitals(
                PatientId $patientId,
                int $limit,
                int $staleAfterDays,
                ?Deadline $deadline = null,
            ): array {
                return [];
            }

            public function staleVitals(
                PatientId $patientId,
                int $limit,
                int $staleAfterDays,
                ?Deadline $deadline = null,
            ): array {
                return [];
            }

            public function recentEncounters(PatientId $patientId, int $limit, ?Deadline $deadline = null): array
            {
                return [];
            }

            public function recentNotes(PatientId $patientId, int $limit, ?Deadline $deadline = null): array
            {
                return [];
            }
        };
    }
}
