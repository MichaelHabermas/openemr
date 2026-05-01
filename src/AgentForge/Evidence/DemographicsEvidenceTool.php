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
        $missing = [];
        foreach (['name' => $name, 'date of birth' => $dob, 'sex' => $sex] as $label => $value) {
            if ($value === '') {
                $missing[] = sprintf('Demographics %s not found in the chart.', $label);
            }
        }

        if ($name === '' && $dob === '' && $sex === '') {
            return new EvidenceResult($this->section(), [], $missing);
        }

        $parts = [];
        if ($name !== '') {
            $parts[] = $name;
        }
        if ($dob !== '') {
            $parts[] = 'born ' . $dob;
        }
        if ($sex !== '') {
            $parts[] = 'sex ' . $sex;
        }

        return new EvidenceResult(
            $this->section(),
            [
                new EvidenceItem(
                    'demographic',
                    'patient_data',
                    EvidenceRowValue::firstString($row, 'pid') ?: (string) $patientId->value,
                    EvidenceRowValue::dateOnly($row, 'date', 'DOB'),
                    'Patient',
                    implode(', ', $parts),
                ),
            ],
            $missing,
        );
    }

}
