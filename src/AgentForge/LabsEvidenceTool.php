<?php

/**
 * Read-only recent lab evidence for the active patient.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\AgentForge;

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
            $label = trim((string) ($row['result_text'] ?? ''));
            $result = trim((string) ($row['result'] ?? ''));
            if ($label === '' || $result === '') {
                continue;
            }

            $units = trim((string) ($row['units'] ?? ''));
            $items[] = new EvidenceItem(
                'lab',
                'procedure_result',
                $this->sourceId($row),
                $this->dateOnly($row['date'] ?? ''),
                $label,
                trim($result . ' ' . $units),
            );
        }

        return EvidenceResult::found($this->section(), $items);
    }

    /** @param array<string, mixed> $row */
    private function sourceId(array $row): string
    {
        return trim((string) ($row['comments'] ?? '')) !== ''
            ? (string) $row['comments']
            : (string) $row['procedure_result_id'];
    }

    private function dateOnly(mixed $value): string
    {
        $date = trim((string) $value);

        return $date === '' ? 'unknown' : substr($date, 0, 10);
    }
}
