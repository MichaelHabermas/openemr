<?php

/**
 * Read-only recent lab evidence for the active patient.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\AgentForge\Evidence;

use OpenEMR\AgentForge\Auth\PatientId;

final readonly class LabsEvidenceTool implements ChartEvidenceTool
{
    public function __construct(private ChartEvidenceRepository $repository, private int $limit = 20)
    {
    }

    public function section(): string
    {
        return 'Recent labs';
    }

    public function collect(PatientId $patientId): EvidenceResult
    {
        $items = [];
        foreach ($this->repository->recentLabs($patientId, $this->limit) as $row) {
            $label = EvidenceRowValue::string($row, 'result_text');
            $result = EvidenceRowValue::string($row, 'result');
            if ($label === '' || $result === '') {
                continue;
            }

            $units = EvidenceRowValue::string($row, 'units');
            $items[] = new EvidenceItem(
                'lab',
                'procedure_result',
                $this->sourceId($row),
                EvidenceRowValue::dateOnly($row, 'date'),
                $label,
                trim($result . ' ' . $units),
            );
        }

        return EvidenceResult::found($this->section(), $items);
    }

    /** @param array<string, mixed> $row */
    private function sourceId(array $row): string
    {
        return EvidenceRowValue::firstString($row, 'comments', 'procedure_result_id');
    }
}
