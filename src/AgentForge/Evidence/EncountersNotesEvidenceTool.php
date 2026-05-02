<?php

/**
 * Read-only recent encounter and last-plan evidence for the active patient.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\AgentForge\Evidence;

use OpenEMR\AgentForge\Auth\PatientId;

final readonly class EncountersNotesEvidenceTool implements ChartEvidenceTool
{
    public function __construct(
        private ChartEvidenceRepository $repository,
        private int $limit = 5,
        private int $maxNoteLength = 500,
    ) {
    }

    public function section(): string
    {
        return 'Recent notes and last plan';
    }

    public function collect(PatientId $patientId): EvidenceResult
    {
        $items = [];
        foreach ($this->repository->recentNotes($patientId, $this->limit) as $row) {
            if (!EvidenceRowValue::truthy($row, 'activity') || !EvidenceRowValue::truthy($row, 'authorized')) {
                continue;
            }

            $reason = EvidenceRowValue::string($row, 'encounter_reason');
            if ($reason !== '') {
                $items[] = new EvidenceItem(
                    'encounter',
                    'form_encounter',
                    EvidenceRowValue::firstString($row, 'encounter'),
                    EvidenceRowValue::dateOnly($row, 'encounter_date', 'note_date'),
                    'Reason for visit',
                    EvidenceText::bounded($reason, $this->maxNoteLength),
                );
            }

            $note = EvidenceRowValue::string($row, 'description');
            if ($note === '') {
                continue;
            }

            $items[] = new EvidenceItem(
                'note',
                'form_clinical_notes',
                $this->sourceId($row),
                EvidenceRowValue::dateOnly($row, 'note_date', 'encounter_date'),
                EvidenceRowValue::string($row, 'codetext') ?: 'Last plan',
                EvidenceText::bounded($note, $this->maxNoteLength),
            );
        }

        return EvidenceResult::found($this->section(), $items);
    }

    /** @param array<string, mixed> $row */
    private function sourceId(array $row): string
    {
        return EvidenceRowValue::firstString($row, 'external_id', 'id');
    }
}
