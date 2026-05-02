<?php

/**
 * Read-only recent encounter reason evidence for the active patient.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\AgentForge\Evidence;

use OpenEMR\AgentForge\Auth\PatientId;

final readonly class RecentEncountersEvidenceTool implements ChartEvidenceTool
{
    public function __construct(
        private ChartEvidenceRepository $repository,
        private int $limit = 5,
        private int $maxReasonLength = 500,
    ) {
    }

    public function section(): string
    {
        return 'Recent encounters';
    }

    public function collect(PatientId $patientId): EvidenceResult
    {
        $items = [];
        foreach ($this->repository->recentEncounters($patientId, $this->limit) as $row) {
            $reason = EvidenceRowValue::string($row, 'reason');
            if ($reason === '') {
                continue;
            }

            $items[] = new EvidenceItem(
                'encounter',
                'form_encounter',
                EvidenceRowValue::firstString($row, 'encounter'),
                EvidenceRowValue::dateOnly($row, 'encounter_date'),
                'Reason for visit',
                EvidenceText::bounded($reason, $this->maxReasonLength),
            );
        }

        if ($items === []) {
            return EvidenceResult::missing(
                $this->section(),
                'Recent encounter reasons not found in the chart.',
            );
        }

        return EvidenceResult::found($this->section(), $items);
    }
}
