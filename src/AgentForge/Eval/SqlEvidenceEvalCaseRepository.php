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

use RuntimeException;

/**
 * @phpstan-type FixtureFact array{
 *     source_type: string,
 *     source_table: string,
 *     source_id: string,
 *     source_date: string,
 *     display_label?: string,
 *     value?: string,
 * }
 *
 * @phpstan-type FixturePatient array{
 *     pid: int,
 *     pubpid?: string,
 *     dob?: string,
 *     sex?: string,
 *     reason_for_visit?: string,
 *     name?: array{first: string, last: string},
 *     facts?: array<string, mixed>,
 *     known_missing?: list<array<string, mixed>>,
 *     unsupported_requests?: list<array<string, mixed>>,
 *     expected_not_promoted?: list<array<string, mixed>>,
 *     expected_absent_source_ids?: list<mixed>,
 * }
 *
 * @phpstan-type GroundTruthFixture array{
 *     fixture_version?: string,
 *     patients: list<FixturePatient>,
 * }
 */
final class SqlEvidenceEvalCaseRepository
{
    /** @return list<SqlEvidenceEvalCase> */
    public function load(string $groundTruthPath): array
    {
        $groundTruth = $this->loadFixture($groundTruthPath);
        $patients = [];
        foreach ($groundTruth['patients'] as $patient) {
            $patients[$patient['pid']] = $patient;
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
                    'instructions: Take 1 tablet by mouth daily with evening meal',
                    'source code: ICD10:E11.9',
                    'result code: 4548-4',
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
                    'inactive_medication_history',
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
                    'Inactive medication history: Warfarin 2 mg',
                    'inactive historical row',
                    'Sulfonamide antibiotics',
                    'Estimated GFR',
                    'order code: 33914-3',
                    'Warfarin is documented as stopped',
                ],
                [],
            ),
            new SqlEvidenceEvalCase(
                'sparse_chart_900003',
                900003,
                'Jordan sparse chart reports checked-but-missing sections without stale evidence promotion.',
                $this->citations($patients[900003] ?? [], [
                    'demographics',
                    'recent_encounters',
                    'active_problems',
                    'stale_vitals',
                ]),
                $this->missingSections($patients[900003] ?? []),
                $this->expectedAbsentSourceIds($patients[900003] ?? []),
                [
                    'Jordan Sparsechart',
                    'Seasonal allergic rhinitis',
                    'Sparse chart orientation visit',
                    'Last-known stale blood pressure',
                    'stale; not within 180 days',
                ],
                ['normal labs'],
            ),
        ];
    }

    public function fixtureVersion(string $groundTruthPath): string
    {
        $fixture = $this->loadFixture($groundTruthPath);

        return $fixture['fixture_version'] ?? 'unknown';
    }

    /** @return GroundTruthFixture */
    private function loadFixture(string $groundTruthPath): array
    {
        $raw = file_get_contents($groundTruthPath);
        if ($raw === false) {
            throw new RuntimeException(sprintf('Cannot read fixture: %s', $groundTruthPath));
        }
        $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($decoded) || !isset($decoded['patients']) || !is_array($decoded['patients'])) {
            throw new RuntimeException(sprintf('Invalid fixture shape: %s', $groundTruthPath));
        }
        foreach ($decoded['patients'] as $patient) {
            if (!is_array($patient) || !isset($patient['pid']) || !is_int($patient['pid'])) {
                throw new RuntimeException(sprintf('Invalid patient shape in fixture: %s', $groundTruthPath));
            }
        }
        /** @var GroundTruthFixture $decoded */
        return $decoded;
    }

    /**
     * @param FixturePatient|array{} $patient
     * @param list<string> $sections
     * @return list<string>
     */
    private function citations(array $patient, array $sections): array
    {
        $rawFacts = $patient['facts'] ?? null;
        $facts = is_array($rawFacts) ? $rawFacts : [];
        $citations = [];
        foreach ($sections as $section) {
            $value = $facts[$section] ?? null;
            foreach ($this->evidenceRows($value) as $row) {
                $citations[] = $this->citation($row);
            }
        }

        return array_values(array_filter($citations));
    }

    /** @return list<array<string, mixed>> */
    private function evidenceRows(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        if (isset($value['source_type'])) {
            return [$value];
        }

        $rows = [];
        foreach ($value as $row) {
            if (is_array($row)) {
                $rows[] = $row;
            }
        }

        return $rows;
    }

    /** @param array<string, mixed> $row */
    private function citation(array $row): string
    {
        $type = $row['source_type'] ?? null;
        $table = $row['source_table'] ?? null;
        $id = $row['source_id'] ?? null;
        $date = $row['source_date'] ?? null;
        if (!is_string($type) || !is_string($table) || !is_string($id) || !is_string($date)) {
            return '';
        }
        if (trim($type) === '' || trim($table) === '' || trim($id) === '' || trim($date) === '') {
            return '';
        }

        return sprintf('%s:%s/%s@%s', $type, $table, $id, $date);
    }

    /**
     * @param FixturePatient|array{} $patient
     * @return list<string>
     */
    private function missingSections(array $patient): array
    {
        $known = $patient['known_missing'] ?? [];
        if (!is_array($known)) {
            return [];
        }
        $sections = [];
        foreach ($known as $missing) {
            if (!is_array($missing)) {
                continue;
            }
            $section = $missing['section'] ?? null;
            if (is_string($section)) {
                $sections[] = $section;
            }
        }

        return $sections;
    }

    /**
     * @param FixturePatient|array{} $patient
     * @return list<string>
     */
    private function forbiddenCitations(array $patient): array
    {
        $expected = $patient['expected_not_promoted'] ?? [];
        if (!is_array($expected)) {
            return [];
        }
        $citations = [];
        foreach ($expected as $row) {
            if (!is_array($row)) {
                continue;
            }
            $citation = $this->citation($row);
            if ($citation !== '') {
                $citations[] = $citation;
            }
        }

        return $citations;
    }

    /**
     * @param FixturePatient|array{} $patient
     * @return list<string>
     */
    private function expectedAbsentSourceIds(array $patient): array
    {
        $sourceIds = $patient['expected_absent_source_ids'] ?? [];
        if (!is_array($sourceIds)) {
            return [];
        }
        $result = [];
        foreach ($sourceIds as $sourceId) {
            if (is_string($sourceId) && trim($sourceId) !== '') {
                $result[] = $sourceId;
            }
        }

        return $result;
    }
}
