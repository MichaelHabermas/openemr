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

use OpenEMR\AgentForge\ChartEvidenceRepository;
use OpenEMR\AgentForge\DemographicsEvidenceTool;
use OpenEMR\AgentForge\EncountersNotesEvidenceTool;
use OpenEMR\AgentForge\LabsEvidenceTool;
use OpenEMR\AgentForge\PatientId;
use OpenEMR\AgentForge\PrescriptionsEvidenceTool;
use OpenEMR\AgentForge\ProblemsEvidenceTool;
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

    public function testPrescriptionsToolReturnsActivePrescriptionEvidence(): void
    {
        $result = (new PrescriptionsEvidenceTool($this->repository()))->collect(new PatientId(900001));

        $this->assertCount(2, $result->items);
        $this->assertSame('Metformin ER 500 mg', $result->items[0]->displayLabel);
        $this->assertSame('Take 1 tablet by mouth daily with evening meal', $result->items[0]->value);
        $this->assertSame('medication:prescriptions/af-rx-metformin@2026-03-15', $result->items[0]->citation());
    }

    public function testPrescriptionsToolDoesNotInventMissingInstructions(): void
    {
        $result = (new PrescriptionsEvidenceTool($this->repository(prescriptions: [
            [
                'id' => 1,
                'external_id' => 'af-rx-empty',
                'start_date' => '2026-03-15',
                'drug' => 'Medication Without Instructions',
                'drug_dosage_instructions' => '',
            ],
        ])))->collect(new PatientId(900001));

        $this->assertSame('Active prescription; instructions not found in the chart.', $result->items[0]->value);
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

    private function repository(
        ?array $demographics = null,
        ?array $problems = null,
        ?array $prescriptions = null,
        ?array $labs = null,
        ?array $notes = null,
    ): ChartEvidenceRepository {
        return new class ($demographics, $problems, $prescriptions, $labs, $notes) implements ChartEvidenceRepository {
            public function __construct(
                private readonly ?array $demographics,
                private readonly ?array $problems,
                private readonly ?array $prescriptions,
                private readonly ?array $labs,
                private readonly ?array $notes,
            ) {
            }

            public function demographics(PatientId $patientId): ?array
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

            public function activeProblems(PatientId $patientId, int $limit): array
            {
                return $this->problems ?? [
                    [
                        'id' => 1,
                        'external_id' => 'af-prob-diabetes',
                        'title' => 'Type 2 diabetes mellitus',
                        'begdate' => '2025-09-10 00:00:00',
                        'date' => '2025-09-10 09:00:00',
                    ],
                    [
                        'id' => 2,
                        'external_id' => 'af-prob-htn',
                        'title' => 'Essential hypertension',
                        'begdate' => '2024-02-18 00:00:00',
                        'date' => '2024-02-18 09:00:00',
                    ],
                ];
            }

            public function activePrescriptions(PatientId $patientId, int $limit): array
            {
                return $this->prescriptions ?? [
                    [
                        'id' => 1,
                        'external_id' => 'af-rx-metformin',
                        'start_date' => '2026-03-15',
                        'drug' => 'Metformin ER 500 mg',
                        'drug_dosage_instructions' => 'Take 1 tablet by mouth daily with evening meal',
                    ],
                    [
                        'id' => 2,
                        'external_id' => 'af-rx-lisinopril',
                        'start_date' => '2026-03-15',
                        'drug' => 'Lisinopril 10 mg',
                        'drug_dosage_instructions' => 'Take 1 tablet by mouth daily',
                    ],
                ];
            }

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
                    ],
                ];
            }
        };
    }
}
