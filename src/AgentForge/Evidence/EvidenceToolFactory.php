<?php

/**
 * Default AgentForge evidence tool composition.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\AgentForge\Evidence;

use OpenEMR\AgentForge\DatabaseExecutor;
use OpenEMR\AgentForge\Time\MonotonicClock;

final class EvidenceToolFactory
{
    /** @return list<ChartEvidenceTool> */
    public static function createDefault(
        ChartEvidenceRepository $repository,
        MonotonicClock $clock,
        DatabaseExecutor $executor,
    ): array {
        return [
            new DemographicsEvidenceTool($repository),
            new RecentEncountersEvidenceTool($repository),
            new ProblemsEvidenceTool($repository),
            new ActiveMedicationsEvidenceTool($repository),
            new InactiveMedicationHistoryEvidenceTool($repository),
            new AllergiesEvidenceTool($repository),
            new LabsEvidenceTool($repository),
            new PatientDocumentFactsEvidenceTool($executor),
            new RecentVitalsEvidenceTool($repository),
            new StaleVitalsEvidenceTool($repository),
            new EncountersNotesEvidenceTool($repository),
        ];
    }
}
