<?php

/**
 * Read-only active medication evidence for the active patient.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\AgentForge\Evidence;

use OpenEMR\AgentForge\Auth\PatientId;

final readonly class ActiveMedicationsEvidenceTool implements ChartEvidenceTool
{
    public function __construct(
        private ChartEvidenceRepository $repository,
        private int $limit = 10,
        private int $maxValueLength = 300,
    ) {
    }

    public function section(): string
    {
        return 'Active medications';
    }

    public function collect(PatientId $patientId): EvidenceResult
    {
        $items = [];
        foreach ($this->repository->activeMedications($patientId, $this->limit) as $row) {
            if (!$this->active($row)) {
                continue;
            }

            $label = EvidenceRowValue::firstString($row, 'drug', 'title');
            if ($label === '') {
                continue;
            }

            $items[] = new EvidenceItem(
                'medication',
                $this->sourceTable($row),
                $this->sourceId($row),
                EvidenceRowValue::dateOnly($row, 'start_date', 'date_added', 'begdate', 'date'),
                EvidenceText::bounded($label, 120),
                EvidenceText::bounded($this->value($row), $this->maxValueLength),
            );
        }

        if ($items === []) {
            return EvidenceResult::missing(
                $this->section(),
                'Active medications not found in checked chart sources: prescriptions, lists, lists_medication.',
            );
        }

        return EvidenceResult::found($this->section(), $items);
    }

    /** @param array<string, mixed> $row */
    private function active(array $row): bool
    {
        return EvidenceRowValue::truthy($row, 'active') || EvidenceRowValue::truthy($row, 'activity');
    }

    /** @param array<string, mixed> $row */
    private function sourceTable(array $row): string
    {
        $sourceTable = EvidenceRowValue::string($row, 'source_table');

        return in_array($sourceTable, ['prescriptions', 'lists', 'lists_medication'], true)
            ? $sourceTable
            : 'unknown_medication_source';
    }

    /** @param array<string, mixed> $row */
    private function sourceId(array $row): string
    {
        if ($this->sourceTable($row) === 'lists_medication') {
            return EvidenceRowValue::firstString($row, 'lists_medication_id', 'list_external_id', 'list_id');
        }

        if ($this->sourceTable($row) === 'lists') {
            return EvidenceRowValue::firstString($row, 'list_external_id', 'list_id');
        }

        return EvidenceRowValue::firstString($row, 'external_id', 'id');
    }

    /** @param array<string, mixed> $row */
    private function value(array $row): string
    {
        $instructions = EvidenceRowValue::string($row, 'drug_dosage_instructions');
        if ($instructions !== '') {
            return $instructions;
        }

        $metadata = array_filter([
            EvidenceRowValue::string($row, 'usage_category_title'),
            EvidenceRowValue::string($row, 'request_intent_title'),
        ]);

        if ($metadata !== []) {
            return 'Active medication; ' . implode('; ', $metadata) . '.';
        }

        return $this->sourceTable($row) === 'prescriptions'
            ? 'Active prescription; instructions not found in the chart.'
            : 'Active medication-list entry; instructions not found in the chart.';
    }
}
