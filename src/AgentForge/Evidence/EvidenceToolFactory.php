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

final class EvidenceToolFactory
{
    /** @return list<ChartEvidenceTool> */
    public static function createDefault(ChartEvidenceRepository $repository): array
    {
        return [
            new DemographicsEvidenceTool($repository),
            new ProblemsEvidenceTool($repository),
            new ActiveMedicationsEvidenceTool($repository),
            new AllergiesEvidenceTool($repository),
            new LabsEvidenceTool($repository),
            new RecentVitalsEvidenceTool($repository),
            new EncountersNotesEvidenceTool($repository),
        ];
    }
}
