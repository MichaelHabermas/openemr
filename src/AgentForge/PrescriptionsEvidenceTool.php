<?php

/**
 * Read-only active prescription evidence for the active patient.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\AgentForge;

final readonly class PrescriptionsEvidenceTool implements ChartEvidenceTool
{
    public function __construct(
        private ChartEvidenceRepository $repository,
        private int $limit = 10,
        private int $maxValueLength = 300,
    ) {
    }

    public function section(): string
    {
        return 'Active prescriptions';
    }

    public function collect(PatientId $patientId): EvidenceResult
    {
        $items = [];
        foreach ($this->repository->activePrescriptions($patientId, $this->limit) as $row) {
            if (!$this->truthy($row['active'] ?? null)) {
                continue;
            }

            $drug = trim((string) ($row['drug'] ?? ''));
            if ($drug === '') {
                continue;
            }

            $instructions = trim((string) ($row['drug_dosage_instructions'] ?? ''));
            $value = $instructions !== '' ? $instructions : 'Active prescription; instructions not found in the chart.';
            $items[] = new EvidenceItem(
                'medication',
                'prescriptions',
                $this->sourceId($row),
                $this->dateOnly($row['start_date'] ?? $row['date_added'] ?? ''),
                EvidenceText::bounded($drug, 120),
                EvidenceText::bounded($value, $this->maxValueLength),
            );
        }

        return EvidenceResult::found($this->section(), $items);
    }

    /** @param array<string, mixed> $row */
    private function sourceId(array $row): string
    {
        return trim((string) ($row['external_id'] ?? '')) !== ''
            ? (string) $row['external_id']
            : (string) $row['id'];
    }

    private function dateOnly(mixed $value): string
    {
        $date = trim((string) $value);

        return $date === '' ? 'unknown' : substr($date, 0, 10);
    }

    private function truthy(mixed $value): bool
    {
        return in_array($value, [1, '1', true], true);
    }
}
