<?php

/**
 * Isolated tests for AgentForge read-only evidence tools.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Tests\Isolated\AgentForge;

use OpenEMR\AgentForge\Auth\PatientId;
use OpenEMR\AgentForge\Evidence\ActiveMedicationsEvidenceTool;
use OpenEMR\AgentForge\Evidence\ChartEvidenceRepository;
use OpenEMR\AgentForge\Evidence\DemographicsEvidenceTool;
use OpenEMR\AgentForge\Evidence\EncountersNotesEvidenceTool;
use OpenEMR\AgentForge\Evidence\LabsEvidenceTool;
use OpenEMR\AgentForge\Evidence\ProblemsEvidenceTool;
use PHPUnit\Framework\TestCase;

final class EvidenceToolsTest extends TestCase
{
    public function testDemographicsToolReturnsFakePatientWithSourceMetadata(): void
    {
        $result = (new DemographicsEvidenceTool($this->repository()))->collect(new PatientId(900001));

        $this->assertSame('Demographics', $result->section);
        $this->assertCount(1, $result->items);
        $this->assertSame(
            [
                'source_type' => 'demographic',
                'source_table' => 'patient_data',
                'source_id' => '900001',
                'source_date' => '2026-04-15',
                'display_label' => 'Patient',
                'value' => 'Alex Testpatient, born 1976-04-12, sex Female',
            ],
            $result->items[0]->toArray(),
        );
    }

    public function testDemographicsToolRepresentsEmptyFieldsAsMissing(): void
    {
        $result = (new DemographicsEvidenceTool($this->repository(demographics: [
            'pid' => 900001,
            'fname' => '',
            'lname' => '',
            'DOB' => '',
            'sex' => '',
            'date' => '2026-04-15 08:00:00',
        ])))->collect(new PatientId(900001));

        $this->assertSame([], $result->items);
        $this->assertContains('Demographics name not found in the chart.', $result->missingSections);
        $this->assertContains('Demographics date of birth not found in the chart.', $result->missingSections);
        $this->assertContains('Demographics sex not found in the chart.', $result->missingSections);
    }

    public function testProblemsToolReturnsOnlyRepositoryProvidedActiveProblems(): void
    {
        $result = (new ProblemsEvidenceTool($this->repository()))->collect(new PatientId(900001));

        $this->assertCount(2, $result->items);
        $this->assertSame('Type 2 diabetes mellitus', $result->items[0]->displayLabel);
        $this->assertSame('problem:lists/af-prob-diabetes@2025-09-10', $result->items[0]->citation());
    }

    public function testProblemsToolReturnsMissingWhenNoActiveProblemsExist(): void
    {
        $result = (new ProblemsEvidenceTool($this->repository(problems: [])))->collect(new PatientId(900001));

        $this->assertSame([], $result->items);
        $this->assertSame(['Active problems not found in the chart.'], $result->missingSections);
    }

    public function testProblemsToolDoesNotInferActivityWhenRepositoryReturnsInactiveRow(): void
    {
        $result = (new ProblemsEvidenceTool($this->repository(problems: [
            [
                'id' => 1,
                'external_id' => 'af-prob-inactive',
                'title' => 'Inactive problem',
                'begdate' => '2025-09-10 00:00:00',
                'activity' => 0,
            ],
        ])))->collect(new PatientId(900001));

        $this->assertSame([], $result->items);
        $this->assertSame(['Active problems not found in the chart.'], $result->missingSections);
    }

    public function testProblemsToolBoundsLongProblemTitles(): void
    {
        $result = (new ProblemsEvidenceTool($this->repository(problems: [
            [
                'id' => 1,
                'external_id' => 'af-prob-long',
                'title' => str_repeat('p', 500),
                'begdate' => '2025-09-10 00:00:00',
                'activity' => 1,
            ],
        ])))->collect(new PatientId(900001));

        $this->assertSame(123, strlen($result->items[0]->displayLabel));
        $this->assertStringEndsWith('...', $result->items[0]->displayLabel);
    }

    public function testActiveMedicationsToolReturnsPrescriptionEvidence(): void
    {
        $result = (new ActiveMedicationsEvidenceTool($this->repository()))->collect(new PatientId(900001));

        $this->assertCount(2, $result->items);
        $this->assertSame('Metformin ER 500 mg', $result->items[0]->displayLabel);
        $this->assertSame('Take 1 tablet by mouth daily with evening meal', $result->items[0]->value);
        $this->assertSame('medication:prescriptions/af-rx-metformin@2026-03-15', $result->items[0]->citation());
    }

    public function testActiveMedicationsToolReturnsListOnlyMedicationEvidence(): void
    {
        $result = (new ActiveMedicationsEvidenceTool($this->repository(medications: [
            [
                'list_id' => 10,
                'list_external_id' => 'af-med-list-only',
                'begdate' => '2026-02-01',
                'title' => 'Atorvastatin 20 mg',
                'activity' => 1,
                'source_table' => 'lists',
            ],
        ])))->collect(new PatientId(900001));

        $this->assertCount(1, $result->items);
        $this->assertSame('Atorvastatin 20 mg', $result->items[0]->displayLabel);
        $this->assertSame('Active medication-list entry; instructions not found in the chart.', $result->items[0]->value);
        $this->assertSame('medication:lists/af-med-list-only@2026-02-01', $result->items[0]->citation());
    }

    public function testActiveMedicationsToolReturnsLinkedListsMedicationEvidence(): void
    {
        $result = (new ActiveMedicationsEvidenceTool($this->repository(medications: [
            [
                'list_id' => 11,
                'lists_medication_id' => 22,
                'begdate' => '2026-02-02',
                'title' => 'Albuterol inhaler',
                'drug_dosage_instructions' => 'Use 2 puffs every 6 hours as needed',
                'activity' => 1,
                'source_table' => 'lists_medication',
            ],
        ])))->collect(new PatientId(900001));

        $this->assertCount(1, $result->items);
        $this->assertSame('Albuterol inhaler', $result->items[0]->displayLabel);
        $this->assertSame('Use 2 puffs every 6 hours as needed', $result->items[0]->value);
        $this->assertSame('medication:lists_medication/22@2026-02-02', $result->items[0]->citation());
    }

    public function testActiveMedicationsToolSurfacesDuplicateMedicationRowsAsSeparateEvidence(): void
    {
        $result = (new ActiveMedicationsEvidenceTool($this->repository(medications: [
            [
                'id' => 1,
                'external_id' => 'af-rx-metformin',
                'start_date' => '2026-03-15',
                'drug' => 'Metformin ER 500 mg',
                'drug_dosage_instructions' => 'Take 1 tablet by mouth daily with evening meal',
                'active' => 1,
                'source_table' => 'prescriptions',
            ],
            [
                'list_id' => 12,
                'list_external_id' => 'af-list-metformin',
                'begdate' => '2026-03-16',
                'title' => 'Metformin ER 500 mg',
                'drug_dosage_instructions' => 'Patient reports taking twice daily',
                'activity' => 1,
                'source_table' => 'lists_medication',
            ],
        ])))->collect(new PatientId(900001));

        $this->assertCount(2, $result->items);
        $this->assertSame('medication:prescriptions/af-rx-metformin@2026-03-15', $result->items[0]->citation());
        $this->assertSame('medication:lists_medication/af-list-metformin@2026-03-16', $result->items[1]->citation());
        $this->assertSame('Patient reports taking twice daily', $result->items[1]->value);
    }

    public function testActiveMedicationsToolDoesNotInventMissingInstructions(): void
    {
        $result = (new ActiveMedicationsEvidenceTool($this->repository(medications: [
            [
                'id' => 1,
                'external_id' => 'af-rx-empty',
                'start_date' => '2026-03-15',
                'drug' => 'Medication Without Instructions',
                'drug_dosage_instructions' => '',
                'active' => 1,
                'source_table' => 'prescriptions',
            ],
        ])))->collect(new PatientId(900001));

        $this->assertSame('Active prescription; instructions not found in the chart.', $result->items[0]->value);
    }

    public function testActiveMedicationsToolDoesNotInferActivityWhenRepositoryReturnsInactiveRow(): void
    {
        $result = (new ActiveMedicationsEvidenceTool($this->repository(medications: [
            [
                'id' => 1,
                'external_id' => 'af-rx-inactive',
                'start_date' => '2026-03-15',
                'drug' => 'Inactive medication',
                'drug_dosage_instructions' => 'Do not surface this.',
                'active' => 0,
                'source_table' => 'prescriptions',
            ],
        ])))->collect(new PatientId(900001));

        $this->assertSame([], $result->items);
        $this->assertSame(
            ['Active medications not found in checked chart sources: prescriptions, lists, lists_medication.'],
            $result->missingSections,
        );
    }

    public function testActiveMedicationsToolSurfacesUncodedMedicationAsChartEvidence(): void
    {
        $result = (new ActiveMedicationsEvidenceTool($this->repository(medications: [
            [
                'list_id' => 13,
                'begdate' => '2026-02-03',
                'title' => 'Patient-reported supplement',
                'activity' => 1,
                'source_table' => 'lists',
            ],
        ])))->collect(new PatientId(900001));

        $this->assertCount(1, $result->items);
        $this->assertSame('Patient-reported supplement', $result->items[0]->displayLabel);
        $this->assertSame('medication:lists/13@2026-02-03', $result->items[0]->citation());
    }

    public function testActiveMedicationsToolBoundsLongInstructions(): void
    {
        $result = (new ActiveMedicationsEvidenceTool($this->repository(medications: [
            [
                'id' => 1,
                'external_id' => 'af-rx-long',
                'start_date' => '2026-03-15',
                'drug' => 'Long Instruction Medication',
                'drug_dosage_instructions' => str_repeat('a', 1000),
                'active' => 1,
                'source_table' => 'prescriptions',
            ],
        ])))->collect(new PatientId(900001));

        $this->assertSame(303, strlen($result->items[0]->value));
        $this->assertStringEndsWith('...', $result->items[0]->value);
    }

    public function testLabsToolReturnsRecentLabEvidence(): void
    {
        $result = (new LabsEvidenceTool($this->repository()))->collect(new PatientId(900001));

        $this->assertCount(2, $result->items);
        $this->assertSame('Hemoglobin A1c', $result->items[0]->displayLabel);
        $this->assertSame('8.2 %', $result->items[0]->value);
        $this->assertSame('lab:procedure_result/agentforge-a1c-2026-01@2026-01-09', $result->items[0]->citation());
    }

    public function testLabsToolReturnsMissingWhenNoLabsExist(): void
    {
        $result = (new LabsEvidenceTool($this->repository(labs: [])))->collect(new PatientId(900001));

        $this->assertSame(['Recent labs not found in the chart.'], $result->missingSections);
    }

    public function testNotesToolReturnsBoundedLastPlanEvidence(): void
    {
        $result = (new EncountersNotesEvidenceTool(
            $this->repository(),
            maxNoteLength: 80,
        ))->collect(new PatientId(900001));

        $this->assertCount(1, $result->items);
        $this->assertSame('Last plan', $result->items[0]->displayLabel);
        $this->assertStringContainsString('Continue metformin ER and lisinopril', $result->items[0]->value);
        $this->assertLessThanOrEqual(83, strlen($result->items[0]->value));
        $this->assertSame('note:form_clinical_notes/af-note-20260415@2026-04-15', $result->items[0]->citation());
    }

    public function testNotesToolReturnsMissingWhenNoLastPlanExists(): void
    {
        $result = (new EncountersNotesEvidenceTool($this->repository(notes: [])))->collect(new PatientId(900001));

        $this->assertSame(['Recent notes and last plan not found in the chart.'], $result->missingSections);
    }

    public function testNotesToolDoesNotSurfaceUnauthorizedRows(): void
    {
        $result = (new EncountersNotesEvidenceTool($this->repository(notes: [
            [
                'id' => 1,
                'external_id' => 'af-note-unauthorized',
                'note_date' => '2026-04-15',
                'codetext' => 'Last plan',
                'description' => 'Do not surface this note.',
                'activity' => 1,
                'authorized' => 0,
            ],
        ])))->collect(new PatientId(900001));

        $this->assertSame([], $result->items);
        $this->assertSame(['Recent notes and last plan not found in the chart.'], $result->missingSections);
    }

    /**
     * @param array<string, mixed>|null $demographics
     * @param list<array<string, mixed>>|null $problems
     * @param list<array<string, mixed>>|null $medications
     * @param list<array<string, mixed>>|null $labs
     * @param list<array<string, mixed>>|null $notes
     */
    private function repository(
        ?array $demographics = null,
        ?array $problems = null,
        ?array $medications = null,
        ?array $labs = null,
        ?array $notes = null,
    ): ChartEvidenceRepository {
        return new class ($demographics, $problems, $medications, $labs, $notes) implements ChartEvidenceRepository {
            /**
             * @param array<string, mixed>|null $demographics
             * @param list<array<string, mixed>>|null $problems
             * @param list<array<string, mixed>>|null $medications
             * @param list<array<string, mixed>>|null $labs
             * @param list<array<string, mixed>>|null $notes
             */
            public function __construct(
                /** @var array<string, mixed>|null */
                private readonly ?array $demographics,
                /** @var list<array<string, mixed>>|null */
                private readonly ?array $problems,
                /** @var list<array<string, mixed>>|null */
                private readonly ?array $medications,
                /** @var list<array<string, mixed>>|null */
                private readonly ?array $labs,
                /** @var list<array<string, mixed>>|null */
                private readonly ?array $notes,
            ) {
            }

            /** @return array<string, mixed> */
            public function demographics(PatientId $patientId): array
            {
                return $this->demographics ?? [
                    'pid' => 900001,
                    'fname' => 'Alex',
                    'lname' => 'Testpatient',
                    'DOB' => '1976-04-12',
                    'sex' => 'Female',
                    'date' => '2026-04-15 08:00:00',
                ];
            }

            /** @return list<array<string, mixed>> */
            public function activeProblems(PatientId $patientId, int $limit): array
            {
                return $this->problems ?? [
                    [
                        'id' => 1,
                        'external_id' => 'af-prob-diabetes',
                        'title' => 'Type 2 diabetes mellitus',
                        'begdate' => '2025-09-10 00:00:00',
                        'date' => '2025-09-10 09:00:00',
                        'activity' => 1,
                    ],
                    [
                        'id' => 2,
                        'external_id' => 'af-prob-htn',
                        'title' => 'Essential hypertension',
                        'begdate' => '2024-02-18 00:00:00',
                        'date' => '2024-02-18 09:00:00',
                        'activity' => 1,
                    ],
                ];
            }

            /** @return list<array<string, mixed>> */
            public function activeMedications(PatientId $patientId, int $limit): array
            {
                return $this->medications ?? [
                    [
                        'id' => 1,
                        'external_id' => 'af-rx-metformin',
                        'start_date' => '2026-03-15',
                        'drug' => 'Metformin ER 500 mg',
                        'drug_dosage_instructions' => 'Take 1 tablet by mouth daily with evening meal',
                        'active' => 1,
                        'source_table' => 'prescriptions',
                    ],
                    [
                        'id' => 2,
                        'external_id' => 'af-rx-lisinopril',
                        'start_date' => '2026-03-15',
                        'drug' => 'Lisinopril 10 mg',
                        'drug_dosage_instructions' => 'Take 1 tablet by mouth daily',
                        'active' => 1,
                        'source_table' => 'prescriptions',
                    ],
                ];
            }

            /** @return list<array<string, mixed>> */
            public function recentLabs(PatientId $patientId, int $limit): array
            {
                return $this->labs ?? [
                    [
                        'procedure_result_id' => 90000101,
                        'comments' => 'agentforge-a1c-2026-01',
                        'result_text' => 'Hemoglobin A1c',
                        'date' => '2026-01-09 12:00:00',
                        'units' => '%',
                        'result' => '8.2',
                    ],
                    [
                        'procedure_result_id' => 90000102,
                        'comments' => 'agentforge-a1c-2026-04',
                        'result_text' => 'Hemoglobin A1c',
                        'date' => '2026-04-10 12:00:00',
                        'units' => '%',
                        'result' => '7.4',
                    ],
                ];
            }

            /** @return list<array<string, mixed>> */
            public function recentNotes(PatientId $patientId, int $limit): array
            {
                return $this->notes ?? [
                    [
                        'id' => 1,
                        'external_id' => 'af-note-20260415',
                        'note_date' => '2026-04-15',
                        'codetext' => 'Last plan',
                        'description' => 'Continue metformin ER and lisinopril. '
                            . 'Review home blood pressure log at next visit. Recheck A1c in 3 months.',
                        'activity' => 1,
                        'authorized' => 1,
                    ],
                ];
            }
        };
    }
}
