<?php

/**
 * Read-only recent vital-sign evidence for the active patient.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\AgentForge\Evidence;

use OpenEMR\AgentForge\Auth\PatientId;
use OpenEMR\AgentForge\Deadline;

final readonly class RecentVitalsEvidenceTool implements ChartEvidenceTool
{
    public function __construct(
        private ChartEvidenceRepository $repository,
        private int $limit = 3,
        private int $staleAfterDays = 180,
    ) {
    }

    public function section(): string
    {
        return 'Recent vitals';
    }

    public function collect(PatientId $patientId, ?Deadline $deadline = null): EvidenceResult
    {
        $items = [];
        foreach ($this->repository->recentVitals($patientId, $this->limit, $this->staleAfterDays, $deadline) as $row) {
            if (!EvidenceRowValue::truthy($row, 'activity') || !EvidenceRowValue::truthy($row, 'authorized')) {
                continue;
            }

            $sourceDate = EvidenceRowValue::dateOnly($row, 'date');
            $rowId = EvidenceRowValue::firstString($row, 'external_id', 'id');
            foreach (VitalEvidenceValues::fromRow($row) as $field => $evidence) {
                $items[] = new EvidenceItem(
                    'vital',
                    'form_vitals',
                    sprintf('%s-%s', $rowId, $field),
                    $sourceDate,
                    $evidence['label'],
                    $evidence['value'],
                );
            }
        }

        if ($items === []) {
            return EvidenceResult::missing(
                $this->section(),
                sprintf('Recent vitals not found in the chart within %d days.', $this->staleAfterDays),
            );
        }

        return EvidenceResult::found($this->section(), $items);
    }
}
