<?php

/**
 * Read-only active problem evidence for the active patient.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\AgentForge\Evidence;

use OpenEMR\AgentForge\Auth\PatientId;

final readonly class ProblemsEvidenceTool implements ChartEvidenceTool
{
    public function __construct(
        private ChartEvidenceRepository $repository,
        private int $limit = 10,
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
            if (!EvidenceRowValue::truthy($row, 'activity')) {
                continue;
            }

            $title = EvidenceRowValue::string($row, 'title');
            if ($title === '') {
                continue;
            }

            $items[] = new EvidenceItem(
                'problem',
                'lists',
                $this->sourceId($row),
                EvidenceRowValue::dateOnly($row, 'begdate', 'date'),
                EvidenceText::bounded($title, 120),
                'Active',
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
