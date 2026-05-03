<?php

/**
 * Read-only active allergy evidence for the active patient.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\AgentForge\Evidence;

use OpenEMR\AgentForge\Auth\PatientId;
use OpenEMR\AgentForge\Deadline;

final readonly class AllergiesEvidenceTool implements ChartEvidenceTool
{
    public function __construct(
        private ChartEvidenceRepository $repository,
        private int $limit = 10,
        private int $maxValueLength = 300,
    ) {
    }

    public function section(): string
    {
        return 'Allergies';
    }

    public function collect(PatientId $patientId, ?Deadline $deadline = null): EvidenceResult
    {
        $items = [];
        foreach ($this->repository->activeAllergies($patientId, $this->limit, $deadline) as $row) {
            if (!EvidenceRowValue::truthy($row, 'activity')) {
                continue;
            }

            $label = EvidenceRowValue::string($row, 'title');
            if ($label === '') {
                continue;
            }

            $items[] = new EvidenceItem(
                'allergy',
                'lists',
                EvidenceRowValue::firstString($row, 'external_id', 'id'),
                EvidenceRowValue::dateOnly($row, 'begdate', 'date'),
                EvidenceText::bounded($label, 120),
                EvidenceText::bounded($this->value($row), $this->maxValueLength),
            );
        }

        if ($items === []) {
            return EvidenceResult::missing($this->section(), 'Active allergies not found in the chart.');
        }

        return EvidenceResult::found($this->section(), $items);
    }

    /** @param array<string, mixed> $row */
    private function value(array $row): string
    {
        $parts = [];
        foreach ([
            'reaction' => 'reaction',
            'severity_al' => 'severity',
            'verification' => 'verification',
            'comments' => 'comments',
        ] as $key => $label) {
            $value = EvidenceRowValue::string($row, $key);
            if ($value !== '') {
                $parts[] = sprintf('%s: %s', $label, $value);
            }
        }

        return $parts === [] ? 'active allergy' : implode('; ', $parts);
    }
}
