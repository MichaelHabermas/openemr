<?php

/**
 * Read-only demographic evidence for the active patient.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\AgentForge\Evidence;

use OpenEMR\AgentForge\Auth\PatientId;

final readonly class DemographicsEvidenceTool implements ChartEvidenceTool
{
    public function __construct(private ChartEvidenceRepository $repository)
    {
    }

    public function section(): string
    {
        return 'Demographics';
    }

    public function collect(PatientId $patientId): EvidenceResult
    {
        $row = $this->repository->demographics($patientId);
        if ($row === null) {
            return EvidenceResult::missing($this->section(), 'Demographics not found in the chart.');
        }

        $name = trim(EvidenceRowValue::string($row, 'fname') . ' ' . EvidenceRowValue::string($row, 'lname'));
        $dob = EvidenceRowValue::string($row, 'DOB');
        $sex = EvidenceRowValue::string($row, 'sex');
        $sourceId = EvidenceRowValue::firstString($row, 'pid') ?: (string) $patientId->value;
        $sourceDate = EvidenceRowValue::dateOnly($row, 'date', 'DOB');

        $items = [];
        $missing = [];
        foreach (
            [
                ['Patient name', $name, 'name', $sourceId . '-name'],
                ['Date of birth', $dob, 'date of birth', $sourceId . '-dob'],
                ['Sex', $sex, 'sex', $sourceId . '-sex'],
            ] as [$label, $value, $missingLabel, $itemSourceId]
        ) {
            if ($value === '') {
                $missing[] = sprintf('Demographics %s not found in the chart.', $missingLabel);
                continue;
            }
            $items[] = new EvidenceItem(
                'demographic',
                'patient_data',
                $itemSourceId,
                $sourceDate,
                $label,
                $value,
            );
        }

        return new EvidenceResult($this->section(), $items, $missing);
    }

}
