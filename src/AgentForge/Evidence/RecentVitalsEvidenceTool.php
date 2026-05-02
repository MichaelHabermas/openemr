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

    public function collect(PatientId $patientId): EvidenceResult
    {
        $items = [];
        foreach ($this->repository->recentVitals($patientId, $this->limit, $this->staleAfterDays) as $row) {
            if (!EvidenceRowValue::truthy($row, 'activity') || !EvidenceRowValue::truthy($row, 'authorized')) {
                continue;
            }

            $sourceDate = EvidenceRowValue::dateOnly($row, 'date');
            $rowId = EvidenceRowValue::firstString($row, 'external_id', 'id');
            foreach ($this->vitalValues($row) as $field => $evidence) {
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

    /**
     * @param array<string, mixed> $row
     * @return array<string, array{label: string, value: string}>
     */
    private function vitalValues(array $row): array
    {
        $values = [];
        $bps = EvidenceRowValue::string($row, 'bps');
        $bpd = EvidenceRowValue::string($row, 'bpd');
        if ($this->present($bps) && $this->present($bpd)) {
            $values['blood-pressure'] = [
                'label' => 'Blood pressure',
                'value' => sprintf('%s/%s mmHg', $bps, $bpd),
            ];
        }

        foreach ([
            'pulse' => ['Pulse', 'bpm'],
            'temperature' => ['Temperature', 'F'],
            'respiration' => ['Respiration', 'breaths/min'],
            'oxygen_saturation' => ['Oxygen saturation', '%'],
            'weight' => ['Weight', 'lb'],
            'height' => ['Height', 'in'],
            'BMI' => ['BMI', 'kg/m2'],
        ] as $key => [$label, $unit]) {
            $value = EvidenceRowValue::string($row, $key);
            if ($this->present($value)) {
                $values[strtolower(str_replace('_', '-', $key))] = [
                    'label' => $label,
                    'value' => sprintf('%s %s', $value, $unit),
                ];
            }
        }

        return $values;
    }

    private function present(string $value): bool
    {
        if ($value === '') {
            return false;
        }

        return (float) $value !== 0.0;
    }
}
