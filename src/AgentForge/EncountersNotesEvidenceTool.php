<?php

/**
 * Read-only recent encounter and last-plan evidence for the active patient.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\AgentForge;

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
            $note = trim((string) ($row['description'] ?? ''));
            if ($note === '') {
                continue;
            }

            $items[] = new EvidenceItem(
                'note',
                'form_clinical_notes',
                $this->sourceId($row),
                $this->dateOnly($row['note_date'] ?? $row['encounter_date'] ?? ''),
                trim((string) ($row['codetext'] ?? 'Last plan')) ?: 'Last plan',
                $this->bounded($note),
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

    private function bounded(string $value): string
    {
        if (strlen($value) <= $this->maxNoteLength) {
            return $value;
        }

        return rtrim(substr($value, 0, $this->maxNoteLength)) . '...';
    }
}
