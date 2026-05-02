<?php

/**
 * Read-only stale last-known vital-sign evidence for sparse charts.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\AgentForge\Evidence;

use OpenEMR\AgentForge\Auth\PatientId;

final readonly class StaleVitalsEvidenceTool implements ChartEvidenceTool
{
    public function __construct(
        private ChartEvidenceRepository $repository,
        private int $limit = 1,
        private int $staleAfterDays = 180,
    ) {
    }

    public function section(): string
    {
        return 'Last-known stale vitals';
    }

    public function collect(PatientId $patientId): EvidenceResult
    {
        $items = [];
        foreach ($this->repository->staleVitals($patientId, $this->limit, $this->staleAfterDays) as $row) {
            if (!EvidenceRowValue::truthy($row, 'activity') || !EvidenceRowValue::truthy($row, 'authorized')) {
                continue;
            }

            $sourceDate = EvidenceRowValue::dateOnly($row, 'date');
            $rowId = EvidenceRowValue::firstString($row, 'external_id', 'id');
            foreach (VitalEvidenceValues::fromRow($row) as $field => $evidence) {
                $items[] = new EvidenceItem(
                    'vital',
                    'form_vitals',
                    sprintf('%s-stale-%s', $rowId, $field),
                    $sourceDate,
                    sprintf('Last-known stale %s', lcfirst($evidence['label'])),
                    sprintf('%s (stale; not within %d days)', $evidence['value'], $this->staleAfterDays),
                );
            }
        }

        if ($items === []) {
            return EvidenceResult::missing(
                $this->section(),
                sprintf('Last-known stale vitals not found outside the %d day recent window.', $this->staleAfterDays),
            );
        }

        return EvidenceResult::found($this->section(), $items);
    }
}
