<?php

/**
 * Read-only demographic evidence for the active patient.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\AgentForge;

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

        $name = trim((string) ($row['fname'] ?? '') . ' ' . (string) ($row['lname'] ?? ''));
        $dob = trim((string) ($row['DOB'] ?? ''));
        $sex = trim((string) ($row['sex'] ?? ''));
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
                    (string) ($row['pid'] ?? $patientId->value),
                    $this->dateOnly($row['date'] ?? $dob),
                    'Patient',
                    implode(', ', $parts),
                ),
            ],
            $missing,
        );
    }

    private function dateOnly(mixed $value): string
    {
        $date = trim((string) $value);
        if ($date === '') {
            return 'unknown';
        }

        return substr($date, 0, 10);
    }
}
