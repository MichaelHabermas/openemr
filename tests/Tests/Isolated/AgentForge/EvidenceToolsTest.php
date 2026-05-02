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
use OpenEMR\AgentForge\Evidence\AllergiesEvidenceTool;
use OpenEMR\AgentForge\Evidence\ChartEvidenceRepository;
use OpenEMR\AgentForge\Evidence\DemographicsEvidenceTool;
use OpenEMR\AgentForge\Evidence\EncountersNotesEvidenceTool;
use OpenEMR\AgentForge\Evidence\InactiveMedicationHistoryEvidenceTool;
use OpenEMR\AgentForge\Evidence\LabsEvidenceTool;
use OpenEMR\AgentForge\Evidence\ProblemsEvidenceTool;
use OpenEMR\AgentForge\Evidence\RecentEncountersEvidenceTool;
use OpenEMR\AgentForge\Evidence\RecentVitalsEvidenceTool;
use OpenEMR\AgentForge\Evidence\StaleVitalsEvidenceTool;
use PHPUnit\Framework\TestCase;

final class EvidenceToolsTest extends TestCase
{
    public function testDemographicsToolReturnsFakePatientWithSourceMetadata(): void
    {
        $result = (new DemographicsEvidenceTool($this->repository()))->collect(new PatientId(900001));

        $this->assertSame('Demographics', $result->section);
        $this->assertCount(3, $result->items);
        $this->assertSame(
            [
                'source_type' => 'demographic',
                'source_table' => 'patient_data',
                'source_id' => '900001-name',
                'source_date' => '2026-04-15',
                'display_label' => 'Patient name',
                'value' => 'Alex Testpatient',
            ],
            $result->items[0]->toArray(),
        );
        $this->assertSame(
            [
                'source_type' => 'demographic',
                'source_table' => 'patient_data',
                'source_id' => '900001-dob',
                'source_date' => '2026-04-15',
                'display_label' => 'Date of birth',
                'value' => '1976-04-12',
            ],
            $result->items[1]->toArray(),
        );
        $this->assertSame(
            [
                'source_type' => 'demographic',
                'source_table' => 'patient_data',
                'source_id' => '900001-sex',
                'source_date' => '2026-04-15',
                'display_label' => 'Sex',
                'value' => 'Female',
            ],
            $result->items[2]->toArray(),
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
        $this->assertSame('Active; source code: ICD10:E11.9', $result->items[0]->value);
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
        $this->assertSame('500 mg; instructions: Take 1 tablet by mouth daily with evening meal', $result->items[0]->value);
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
        $this->assertSame('active medication', $result->items[0]->value);
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
        $this->assertSame('instructions: Use 2 puffs every 6 hours as needed', $result->items[0]->value);
        $this->assertSame('medication:lists_medication/22@2026-02-02', $result->items[0]->citation());
    }

    public function testActiveMedicationsToolPrefersStableListExternalIdForLinkedMedicationEvidence(): void
    {
        $result = (new ActiveMedicationsEvidenceTool($this->repository(medications: [
            [
                'list_id' => 11,
                'list_external_id' => 'af-l900002-metdup',
                'lists_medication_id' => 90000203,
                'begdate' => '2026-05-16',
                'title' => 'Metformin ER 500 mg',
                'activity' => 1,
                'source_table' => 'lists_medication',
            ],
        ])))->collect(new PatientId(900002));

        $this->assertSame('medication:lists_medication/af-l900002-metdup@2026-05-16', $result->items[0]->citation());
    }

    public function testActiveMedicationsToolSurfacesDuplicateMedicationRowsAsSeparateEvidence(): void
    {
        $result = (new ActiveMedicationsEvidenceTool($this->repository(medications: [
            [
                'id' => 1,
                'external_id' => 'af-rx-metformin',
                'start_date' => '2026-03-15',
                'drug' => 'Metformin ER 500 mg',
                'dosage' => '500 mg',
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
        $this->assertSame('500 mg; instructions: Take 1 tablet by mouth daily with evening meal', $result->items[0]->value);
        $this->assertSame('instructions: Patient reports taking twice daily', $result->items[1]->value);
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

        $this->assertSame('active medication', $result->items[0]->value);
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

    public function testActiveMedicationsToolBoundsLongDosage(): void
    {
        $result = (new ActiveMedicationsEvidenceTool($this->repository(medications: [
            [
                'id' => 1,
                'external_id' => 'af-rx-long',
                'start_date' => '2026-03-15',
                'drug' => 'Long Dosage Medication',
                'dosage' => str_repeat('a', 1000),
                'active' => 1,
                'source_table' => 'prescriptions',
            ],
        ])))->collect(new PatientId(900001));

        $this->assertSame(303, strlen($result->items[0]->value));
        $this->assertStringEndsWith('...', $result->items[0]->value);
    }

    public function testActiveMedicationsToolBoundsLongInstructions(): void
    {
        $result = (new ActiveMedicationsEvidenceTool($this->repository(medications: [
            [
                'id' => 1,
                'external_id' => 'af-rx-long-instructions',
                'start_date' => '2026-03-15',
                'drug' => 'Long Instruction Medication',
                'drug_dosage_instructions' => str_repeat('i', 1000),
                'active' => 1,
                'source_table' => 'prescriptions',
            ],
        ])))->collect(new PatientId(900001));

        $this->assertSame(303, strlen($result->items[0]->value));
        $this->assertStringStartsWith('instructions: ', $result->items[0]->value);
        $this->assertStringEndsWith('...', $result->items[0]->value);
    }

    public function testInactiveMedicationHistoryToolSurfacesInactiveMedicationSeparately(): void
    {
        $result = (new InactiveMedicationHistoryEvidenceTool($this->repository(inactiveMedications: [
            [
                'id' => 10,
                'external_id' => 'af-rx-warfarin-stopped',
                'start_date' => '2025-11-20',
                'drug' => 'Warfarin 2 mg',
                'dosage' => '2 mg',
                'drug_dosage_instructions' => 'Historical anticoagulant before apixaban',
                'active' => 0,
                'source_table' => 'prescriptions',
            ],
        ])))->collect(new PatientId(900002));

        $this->assertCount(1, $result->items);
        $this->assertSame('Inactive medication history: Warfarin 2 mg', $result->items[0]->displayLabel);
        $this->assertSame(
            'inactive historical row; strength: 2 mg; instructions: Historical anticoagulant before apixaban',
            $result->items[0]->value,
        );
        $this->assertSame('medication:prescriptions/af-rx-warfarin-stopped@2025-11-20', $result->items[0]->citation());
    }

    public function testInactiveMedicationHistoryToolDoesNotSurfaceActiveRows(): void
    {
        $result = (new InactiveMedicationHistoryEvidenceTool($this->repository(inactiveMedications: [
            [
                'id' => 10,
                'external_id' => 'af-rx-active',
                'start_date' => '2025-11-20',
                'drug' => 'Active medication',
                'active' => 1,
                'source_table' => 'prescriptions',
            ],
        ])))->collect(new PatientId(900002));

        $this->assertSame([], $result->items);
        $this->assertSame(
            ['Inactive medication history not found in checked chart sources: prescriptions, lists, lists_medication.'],
            $result->missingSections,
        );
    }

    public function testAllergiesToolReturnsActiveAllergyEvidence(): void
    {
        $result = (new AllergiesEvidenceTool($this->repository()))->collect(new PatientId(900001));

        $this->assertCount(2, $result->items);
        $this->assertSame('Penicillin', $result->items[0]->displayLabel);
        $this->assertSame('reaction: rash; severity: moderate; verification: confirmed', $result->items[0]->value);
        $this->assertSame('allergy:lists/af-al-penicillin@2026-04-01', $result->items[0]->citation());
    }

    public function testAllergiesToolReturnsMissingWhenNoActiveAllergiesExist(): void
    {
        $result = (new AllergiesEvidenceTool($this->repository(allergies: [])))->collect(new PatientId(900001));

        $this->assertSame([], $result->items);
        $this->assertSame(['Active allergies not found in the chart.'], $result->missingSections);
    }

    public function testAllergiesToolDoesNotInferActivityWhenRepositoryReturnsInactiveRow(): void
    {
        $result = (new AllergiesEvidenceTool($this->repository(allergies: [
            [
                'id' => 1,
                'external_id' => 'af-al-inactive',
                'title' => 'Inactive allergy',
                'begdate' => '2026-04-01',
                'reaction' => 'Do not surface this.',
                'activity' => 0,
            ],
        ])))->collect(new PatientId(900001));

        $this->assertSame([], $result->items);
        $this->assertSame(['Active allergies not found in the chart.'], $result->missingSections);
    }

    public function testLabsToolReturnsRecentLabEvidence(): void
    {
        $result = (new LabsEvidenceTool($this->repository()))->collect(new PatientId(900001));

        $this->assertCount(2, $result->items);
        $this->assertSame('Hemoglobin A1c', $result->items[0]->displayLabel);
        $this->assertSame('8.2 %; result code: 4548-4; order code: 4548-4', $result->items[0]->value);
        $this->assertSame('lab:procedure_result/agentforge-a1c-2026-01@2026-01-09', $result->items[0]->citation());
    }

    public function testLabsToolOmitsEmptyCodes(): void
    {
        $result = (new LabsEvidenceTool($this->repository(labs: [
            [
                'procedure_result_id' => 1,
                'comments' => 'agentforge-lab-no-code',
                'result_text' => 'Glucose',
                'date' => '2026-04-10 12:00:00',
                'units' => 'mg/dL',
                'result' => '101',
                'result_code' => '',
                'procedure_code' => '',
            ],
        ])))->collect(new PatientId(900001));

        $this->assertSame('101 mg/dL', $result->items[0]->value);
    }

    public function testLabsToolReturnsMissingWhenNoLabsExist(): void
    {
        $result = (new LabsEvidenceTool($this->repository(labs: [])))->collect(new PatientId(900001));

        $this->assertSame(['Recent labs not found in the chart.'], $result->missingSections);
    }

    public function testRecentVitalsToolReturnsBoundedRecentVitalEvidence(): void
    {
        $result = (new RecentVitalsEvidenceTool($this->repository(), limit: 1))->collect(new PatientId(900001));

        $this->assertCount(8, $result->items);
        $this->assertSame('Blood pressure', $result->items[0]->displayLabel);
        $this->assertSame('142/88 mmHg', $result->items[0]->value);
        $this->assertSame('vital:form_vitals/af-vitals-20260415-blood-pressure@2026-04-15', $result->items[0]->citation());
        $this->assertSame('Pulse', $result->items[1]->displayLabel);
        $this->assertSame('84 bpm', $result->items[1]->value);
        $this->assertSame('Temperature', $result->items[2]->displayLabel);
        $this->assertSame('98.6 F', $result->items[2]->value);
        $this->assertSame('Oxygen saturation', $result->items[4]->displayLabel);
        $this->assertSame('98 %', $result->items[4]->value);
        $this->assertSame('Weight', $result->items[5]->displayLabel);
        $this->assertSame('184 lb', $result->items[5]->value);
        $this->assertSame('Height', $result->items[6]->displayLabel);
        $this->assertSame('65 in', $result->items[6]->value);
        $this->assertSame('BMI', $result->items[7]->displayLabel);
        $this->assertSame('30.6 kg/m2', $result->items[7]->value);
    }

    public function testRecentVitalsToolReturnsMissingWhenNoRecentVitalsExist(): void
    {
        $result = (new RecentVitalsEvidenceTool($this->repository(vitals: [])))->collect(new PatientId(900001));

        $this->assertSame([], $result->items);
        $this->assertSame(['Recent vitals not found in the chart within 180 days.'], $result->missingSections);
    }

    public function testStaleVitalsToolReturnsLastKnownStaleEvidenceSeparately(): void
    {
        $result = (new StaleVitalsEvidenceTool($this->repository(staleVitals: [
            [
                'id' => 9,
                'external_id' => 'af-vit-900003-stale',
                'date' => '2025-01-03',
                'bps' => '130',
                'bpd' => '82',
                'pulse' => '76',
                'activity' => 1,
                'authorized' => 1,
            ],
        ])))->collect(new PatientId(900003));

        $this->assertCount(2, $result->items);
        $this->assertSame('Last-known stale blood pressure', $result->items[0]->displayLabel);
        $this->assertSame('130/82 mmHg (stale; not within 180 days)', $result->items[0]->value);
        $this->assertSame('vital:form_vitals/af-vit-900003-stale-stale-blood-pressure@2025-01-03', $result->items[0]->citation());
        $this->assertSame('Last-known stale pulse', $result->items[1]->displayLabel);
    }

    public function testRecentVitalsToolDoesNotSurfaceUnauthorizedRows(): void
    {
        $result = (new RecentVitalsEvidenceTool($this->repository(vitals: [
            [
                'id' => 1,
                'external_id' => 'af-vitals-unauthorized',
                'date' => '2026-04-15',
                'bps' => '150',
                'bpd' => '90',
                'activity' => 1,
                'authorized' => 0,
            ],
        ])))->collect(new PatientId(900001));

        $this->assertSame([], $result->items);
        $this->assertSame(['Recent vitals not found in the chart within 180 days.'], $result->missingSections);
    }

    public function testRecentVitalsToolOmitsEmptyAndZeroFields(): void
    {
        $result = (new RecentVitalsEvidenceTool($this->repository(vitals: [
            [
                'id' => 1,
                'external_id' => 'af-vitals-empty',
                'date' => '2026-04-15',
                'bps' => '0',
                'bpd' => '88',
                'pulse' => '0.000000',
                'temperature' => '',
                'oxygen_saturation' => '97.00',
                'activity' => 1,
                'authorized' => 1,
            ],
        ])))->collect(new PatientId(900001));

        $this->assertCount(1, $result->items);
        $this->assertSame('Oxygen saturation', $result->items[0]->displayLabel);
        $this->assertSame('97 %', $result->items[0]->value);
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

    public function testRecentEncountersToolReturnsReasonOnlyEncounterEvidence(): void
    {
        $result = (new RecentEncountersEvidenceTool($this->repository(encounters: [
            [
                'encounter' => 900617,
                'encounter_date' => '2026-06-17',
                'reason' => 'Sparse chart orientation visit with limited imported data.',
            ],
        ])))->collect(new PatientId(900003));

        $this->assertCount(1, $result->items);
        $this->assertSame('Reason for visit', $result->items[0]->displayLabel);
        $this->assertSame('Sparse chart orientation visit with limited imported data.', $result->items[0]->value);
        $this->assertSame('encounter:form_encounter/900617@2026-06-17', $result->items[0]->citation());
    }

    public function testRecentEncountersToolOmitsEmptyReasons(): void
    {
        $result = (new RecentEncountersEvidenceTool($this->repository(encounters: [
            [
                'encounter' => 900617,
                'encounter_date' => '2026-06-17',
                'reason' => '',
            ],
        ])))->collect(new PatientId(900003));

        $this->assertSame([], $result->items);
        $this->assertSame(['Recent encounter reasons not found in the chart.'], $result->missingSections);
    }

    public function testNotesToolKeepsLastPlanSeparateFromEncounterReasonEvidence(): void
    {
        $result = (new EncountersNotesEvidenceTool($this->repository(notes: [
            [
                'id' => 1,
                'external_id' => 'af-note-20260415',
                'note_date' => '2026-04-15',
                'codetext' => 'Last plan',
                'description' => 'Continue metformin ER and lisinopril.',
                'encounter' => 900415,
                'encounter_reason' => 'Follow-up for diabetes and blood pressure before a scheduled primary care visit.',
                'encounter_date' => '2026-04-15',
                'activity' => 1,
                'authorized' => 1,
            ],
        ])))->collect(new PatientId(900001));

        $this->assertCount(1, $result->items);
        $this->assertSame('Last plan', $result->items[0]->displayLabel);
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
     * @param list<array<string, mixed>>|null $allergies
     * @param list<array<string, mixed>>|null $labs
     * @param list<array<string, mixed>>|null $vitals
     * @param list<array<string, mixed>>|null $notes
     */
    private function repository(
        ?array $demographics = null,
        ?array $problems = null,
        ?array $medications = null,
        ?array $inactiveMedications = null,
        ?array $allergies = null,
        ?array $labs = null,
        ?array $vitals = null,
        ?array $staleVitals = null,
        ?array $encounters = null,
        ?array $notes = null,
    ): ChartEvidenceRepository {
        return new class (
            $demographics,
            $problems,
            $medications,
            $inactiveMedications,
            $allergies,
            $labs,
            $vitals,
            $staleVitals,
            $encounters,
            $notes
        ) implements ChartEvidenceRepository {
            /**
             * @param array<string, mixed>|null $demographics
             * @param list<array<string, mixed>>|null $problems
             * @param list<array<string, mixed>>|null $medications
             * @param list<array<string, mixed>>|null $inactiveMedications
             * @param list<array<string, mixed>>|null $allergies
             * @param list<array<string, mixed>>|null $labs
             * @param list<array<string, mixed>>|null $vitals
             * @param list<array<string, mixed>>|null $staleVitals
             * @param list<array<string, mixed>>|null $encounters
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
                private readonly ?array $inactiveMedications,
                /** @var list<array<string, mixed>>|null */
                private readonly ?array $allergies,
                /** @var list<array<string, mixed>>|null */
                private readonly ?array $labs,
                /** @var list<array<string, mixed>>|null */
                private readonly ?array $vitals,
                /** @var list<array<string, mixed>>|null */
                private readonly ?array $staleVitals,
                /** @var list<array<string, mixed>>|null */
                private readonly ?array $encounters,
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
                        'diagnosis' => 'ICD10:E11.9',
                        'activity' => 1,
                    ],
                    [
                        'id' => 2,
                        'external_id' => 'af-prob-htn',
                        'title' => 'Essential hypertension',
                        'begdate' => '2024-02-18 00:00:00',
                        'date' => '2024-02-18 09:00:00',
                        'diagnosis' => 'ICD10:I10',
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
                        'dosage' => '500 mg',
                        'drug_dosage_instructions' => 'Take 1 tablet by mouth daily with evening meal',
                        'active' => 1,
                        'source_table' => 'prescriptions',
                    ],
                    [
                        'id' => 2,
                        'external_id' => 'af-rx-lisinopril',
                        'start_date' => '2026-03-15',
                        'drug' => 'Lisinopril 10 mg',
                        'dosage' => '10 mg',
                        'drug_dosage_instructions' => 'Take 1 tablet by mouth daily',
                        'active' => 1,
                        'source_table' => 'prescriptions',
                    ],
                ];
            }

            /** @return list<array<string, mixed>> */
            public function inactiveMedications(PatientId $patientId, int $limit): array
            {
                return array_slice($this->inactiveMedications ?? [], 0, $limit);
            }

            /** @return list<array<string, mixed>> */
            public function activeAllergies(PatientId $patientId, int $limit): array
            {
                return array_slice($this->allergies ?? [
                    [
                        'id' => 1,
                        'external_id' => 'af-al-penicillin',
                        'title' => 'Penicillin',
                        'begdate' => '2026-04-01',
                        'reaction' => 'rash',
                        'severity_al' => 'moderate',
                        'verification' => 'confirmed',
                        'comments' => '',
                        'activity' => 1,
                    ],
                    [
                        'id' => 2,
                        'external_id' => 'af-al-shellfish',
                        'title' => 'Shellfish',
                        'begdate' => '2025-11-20',
                        'reaction' => 'hives',
                        'severity_al' => 'mild',
                        'verification' => 'confirmed',
                        'activity' => 1,
                    ],
                ], 0, $limit);
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
                        'result_code' => '4548-4',
                        'procedure_code' => '4548-4',
                    ],
                    [
                        'procedure_result_id' => 90000102,
                        'comments' => 'agentforge-a1c-2026-04',
                        'result_text' => 'Hemoglobin A1c',
                        'date' => '2026-04-10 12:00:00',
                        'units' => '%',
                        'result' => '7.4',
                        'result_code' => '4548-4',
                        'procedure_code' => '4548-4',
                    ],
                ];
            }

            /** @return list<array<string, mixed>> */
            public function recentVitals(PatientId $patientId, int $limit, int $staleAfterDays): array
            {
                return array_slice($this->vitals ?? [
                    [
                        'id' => 1,
                        'external_id' => 'af-vitals-20260415',
                        'date' => '2026-04-15 08:25:00',
                        'bps' => '142',
                        'bpd' => '88',
                        'pulse' => '84',
                        'temperature' => '98.60',
                        'respiration' => '16',
                        'oxygen_saturation' => '98.00',
                        'weight' => '184.000000',
                        'height' => '65.000000',
                        'BMI' => '30.600000',
                        'activity' => 1,
                        'authorized' => 1,
                    ],
                    [
                        'id' => 2,
                        'external_id' => 'af-vitals-20260109',
                        'date' => '2026-01-09 12:00:00',
                        'bps' => '138',
                        'bpd' => '84',
                        'pulse' => '80',
                        'activity' => 1,
                        'authorized' => 1,
                    ],
                ], 0, $limit);
            }

            /** @return list<array<string, mixed>> */
            public function staleVitals(PatientId $patientId, int $limit, int $staleAfterDays): array
            {
                return array_slice($this->staleVitals ?? [], 0, $limit);
            }

            /** @return list<array<string, mixed>> */
            public function recentEncounters(PatientId $patientId, int $limit): array
            {
                return array_slice($this->encounters ?? [
                    [
                        'encounter' => 900415,
                        'encounter_date' => '2026-04-15',
                        'reason' => 'Follow-up for diabetes and blood pressure before a scheduled primary care visit.',
                    ],
                ], 0, $limit);
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
