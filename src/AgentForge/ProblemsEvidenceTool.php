<?php

/**
 * Read-only active problem evidence for the active patient.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\AgentForge;

final readonly class ProblemsEvidenceTool implements ChartEvidenceTool
{
    public function __construct(
        private ChartEvidenceRepository $repository,
        private int $limit = 10,
        private int $maxValueLength = 300,
    ) {
    }

    public function section(): string
    {
        return 'Active problems';
    }

    public function collect(PatientId $patientId): EvidenceResult
    {
        $items = [];
        foreach ($this->repository->activeProblems($patientId, $this->limit) as $row) {
            if (!$this->truthy($row['activity'] ?? null)) {
                continue;
            }

            $title = trim((string) ($row['title'] ?? ''));
            if ($title === '') {
                continue;
            }

            $date = $this->dateOnly($row['begdate'] ?? $row['date'] ?? '');
            $items[] = new EvidenceItem(
                'problem',
                'lists',
                $this->sourceId($row),
                $date,
                EvidenceText::bounded($title, 120),
                EvidenceText::bounded(sprintf('Active problem since %s', $date), $this->maxValueLength),
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
