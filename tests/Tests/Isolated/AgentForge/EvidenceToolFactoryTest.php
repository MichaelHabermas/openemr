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

use OpenEMR\AgentForge\Evidence\ChartEvidenceRepository;
use OpenEMR\AgentForge\Evidence\EvidenceToolFactory;
use OpenEMR\AgentForge\Auth\PatientId;
use PHPUnit\Framework\TestCase;

final class EvidenceToolFactoryTest extends TestCase
{
    public function testDefaultFactoryBuildsEveryEpicFiveEvidenceTool(): void
    {
        $tools = EvidenceToolFactory::createDefault($this->emptyRepository());

        $this->assertSame(
            [
                'Demographics',
                'Active problems',
                'Active prescriptions',
                'Recent labs',
                'Recent notes and last plan',
            ],
            array_map(static fn ($tool): string => $tool->section(), $tools),
        );
    }

    private function emptyRepository(): ChartEvidenceRepository
    {
        return new class implements ChartEvidenceRepository {
            public function demographics(PatientId $patientId): ?array
            {
                return null;
            }

            public function activeProblems(PatientId $patientId, int $limit): array
            {
                return [];
            }

            public function activePrescriptions(PatientId $patientId, int $limit): array
            {
                return [];
            }

            public function recentLabs(PatientId $patientId, int $limit): array
            {
                return [];
            }

            public function recentNotes(PatientId $patientId, int $limit): array
            {
                return [];
            }
        };
    }
}
