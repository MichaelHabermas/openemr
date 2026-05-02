<?php

/**
 * Builds SQL evidence eval cases from seeded demo-patient ground truth.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\AgentForge\Eval;

final class SqlEvidenceEvalCaseRepository
{
    /** @return list<SqlEvidenceEvalCase> */
    public function load(string $groundTruthPath): array
    {
        $groundTruth = json_decode((string) file_get_contents($groundTruthPath), true, 512, JSON_THROW_ON_ERROR);
        $patients = [];
        foreach (($groundTruth['patients'] ?? []) as $patient) {
            if (is_array($patient) && isset($patient['pid'])) {
                $patients[(int) $patient['pid']] = $patient;
            }
        }

        return [
            new SqlEvidenceEvalCase(
                'visit_briefing_900001',
                900001,
                'Alex seeded visit briefing ingredients are present and source-carrying.',
                $this->citations($patients[900001] ?? [], [
                    'demographics',
                    'recent_encounters',
                    'active_problems',
                    'active_medications',
                    'active_allergies',
                    'recent_labs',
                    'recent_vitals',
                    'recent_note',
                ]),
                expectedValueFragments: [
                    'Alex Testpatient',
                    'Reason for visit',
                    'Follow-up for diabetes and blood pressure',
                    'Type 2 diabetes mellitus',
                    'Metformin ER 500 mg',
                    'Penicillin',
                    'Hemoglobin A1c',
                    'Blood pressure',
                    'Continue metformin ER and lisinopril',
                ],
            ),
            new SqlEvidenceEvalCase(
                'missing_microalbumin_900001',
                900001,
                'Alex missing microalbumin remains absent from lab evidence.',
                forbiddenValueFragments: ['Microalbumin', 'microalbumin'],
            ),
            new SqlEvidenceEvalCase(
                'polypharmacy_900002',
                900002,
                'Riley dense chart includes active rows and excludes inactive stale rows.',
                $this->citations($patients[900002] ?? [], [
                    'demographics',
                    'recent_encounters',
                    'active_problems',
                    'active_medications',
                    'active_allergies',
                    'recent_labs',
                    'recent_note',
                ]),
                $this->missingSections($patients[900002] ?? []),
                $this->forbiddenCitations($patients[900002] ?? []),
                [
                    'Riley Medmix',
                    'Medication reconciliation for anticoagulation',
                    'Apixaban 5 mg',
                    'Metformin ER 500 mg',
                    'Sulfonamide antibiotics',
                    'Estimated GFR',
                    'Warfarin is documented as stopped',
                ],
                ['Warfarin 2 mg'],
            ),
            new SqlEvidenceEvalCase(
                'sparse_chart_900003',
                900003,
                'Jordan sparse chart reports checked-but-missing sections without stale evidence promotion.',
                $this->citations($patients[900003] ?? [], ['demographics', 'active_problems']),
                $this->missingSections($patients[900003] ?? []),
                $this->expectedAbsentSourceIds($patients[900003] ?? []),
                ['Jordan Sparsechart', 'Seasonal allergic rhinitis'],
                ['normal labs'],
            ),
        ];
    }

    public function fixtureVersion(string $groundTruthPath): string
    {
        $groundTruth = json_decode((string) file_get_contents($groundTruthPath), true, 512, JSON_THROW_ON_ERROR);

        return is_string($groundTruth['fixture_version'] ?? null) ? $groundTruth['fixture_version'] : 'unknown';
    }

    /**
     * @param array<string, mixed> $patient
     * @param list<string> $sections
     * @return list<string>
     */
    private function citations(array $patient, array $sections): array
    {
        $facts = is_array($patient['facts'] ?? null) ? $patient['facts'] : [];
        $citations = [];
        foreach ($sections as $section) {
            $value = $facts[$section] ?? null;
            foreach ($this->evidenceRows($value) as $row) {
                $citations[] = $this->citation($row);
            }
        }

        return array_values(array_filter($citations));
    }

    /** @param mixed $value @return list<array<string, mixed>> */
    private function evidenceRows(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        if (isset($value['source_type'])) {
            return [$value];
        }

        return array_values(array_filter($value, static fn (mixed $row): bool => is_array($row)));
    }

    /** @param array<string, mixed> $row */
    private function citation(array $row): string
    {
        foreach (['source_type', 'source_table', 'source_id', 'source_date'] as $key) {
            if (!is_string($row[$key] ?? null) || trim($row[$key]) === '') {
                return '';
            }
        }

        return sprintf(
            '%s:%s/%s@%s',
            $row['source_type'],
            $row['source_table'],
            $row['source_id'],
            $row['source_date'],
        );
    }

    /** @param array<string, mixed> $patient @return list<string> */
    private function missingSections(array $patient): array
    {
        $sections = [];
        foreach (($patient['known_missing'] ?? []) as $missing) {
            if (is_array($missing) && is_string($missing['section'] ?? null)) {
                $sections[] = $missing['section'];
            }
        }

        return $sections;
    }

    /** @param array<string, mixed> $patient @return list<string> */
    private function forbiddenCitations(array $patient): array
    {
        $citations = [];
        foreach (($patient['expected_not_promoted'] ?? []) as $row) {
            if (is_array($row)) {
                $citation = $this->citation($row);
                if ($citation !== '') {
                    $citations[] = $citation;
                }
            }
        }

        return $citations;
    }

    /** @param array<string, mixed> $patient @return list<string> */
    private function expectedAbsentSourceIds(array $patient): array
    {
        return array_values(array_filter(
            $patient['expected_absent_source_ids'] ?? [],
            static fn (mixed $sourceId): bool => is_string($sourceId) && trim($sourceId) !== '',
        ));
    }
}
