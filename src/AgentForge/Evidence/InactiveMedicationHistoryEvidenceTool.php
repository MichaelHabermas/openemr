<?php

/**
 * Read-only inactive medication history evidence for reconciliation context.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\AgentForge\Evidence;

use OpenEMR\AgentForge\Auth\PatientId;

final readonly class InactiveMedicationHistoryEvidenceTool implements ChartEvidenceTool
{
    public function __construct(
        private ChartEvidenceRepository $repository,
        private int $limit = 10,
        private int $maxValueLength = 300,
    ) {
    }

    public function section(): string
    {
        return 'Inactive medication history';
    }

    public function collect(PatientId $patientId): EvidenceResult
    {
        $items = [];
        foreach ($this->repository->inactiveMedications($patientId, $this->limit) as $row) {
            if ($this->active($row)) {
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
                EvidenceText::bounded(sprintf('Inactive medication history: %s', $label), 160),
                EvidenceText::bounded($this->value($row), $this->maxValueLength),
            );
        }

        if ($items === []) {
            return EvidenceResult::missing(
                $this->section(),
                'Inactive medication history not found in checked chart sources: prescriptions, lists, lists_medication.',
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
            return EvidenceRowValue::firstString($row, 'list_external_id', 'lists_medication_id', 'list_id');
        }

        if ($this->sourceTable($row) === 'lists') {
            return EvidenceRowValue::firstString($row, 'list_external_id', 'list_id');
        }

        return EvidenceRowValue::firstString($row, 'external_id', 'id');
    }

    /** @param array<string, mixed> $row */
    private function value(array $row): string
    {
        $parts = ['inactive historical row'];
        foreach (['dosage' => 'strength', 'drug_dosage_instructions' => 'instructions'] as $key => $label) {
            $value = EvidenceRowValue::string($row, $key);
            if ($value !== '') {
                $parts[] = sprintf('%s: %s', $label, $value);
            }
        }

        return implode('; ', $parts);
    }
}
