<?php

/**
 * SQL-backed read-only chart evidence repository.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\AgentForge\Evidence;

use OpenEMR\AgentForge\Auth\PatientId;
use OpenEMR\AgentForge\DatabaseExecutor;
use OpenEMR\AgentForge\Deadline;

final readonly class SqlChartEvidenceRepository implements ChartEvidenceRepository
{
    public function __construct(private DatabaseExecutor $executor)
    {
    }

    public function demographics(PatientId $patientId, ?Deadline $deadline = null): ?array
    {
        $records = $this->executor->fetchRecords(
            'SELECT pid, fname, lname, DOB, sex, date FROM patient_data WHERE pid = ? LIMIT 1',
            [$patientId->value],
            $deadline,
        );

        return $records[0] ?? null;
    }

    public function activeProblems(PatientId $patientId, int $limit, ?Deadline $deadline = null): array
    {
        return $this->executor->fetchRecords(
            'SELECT id, external_id, date, title, begdate, diagnosis, activity FROM lists '
            . 'WHERE pid = ? AND type = ? AND activity = 1 '
            . 'ORDER BY COALESCE(begdate, date) DESC LIMIT ' . $this->limit($limit),
            [$patientId->value, 'medical_problem'],
            $deadline,
        );
    }

    public function activeMedications(PatientId $patientId, int $limit, ?Deadline $deadline = null): array
    {
        return $this->fetchMedicationsByActivity($patientId, 1, $limit, $deadline);
    }

    public function inactiveMedications(PatientId $patientId, int $limit, ?Deadline $deadline = null): array
    {
        return $this->fetchMedicationsByActivity($patientId, 0, $limit, $deadline);
    }

    /** @return list<array<string, mixed>> */
    private function fetchMedicationsByActivity(
        PatientId $patientId,
        int $activity,
        int $limit,
        ?Deadline $deadline,
    ): array {
        $limit = $this->limit($limit);

        return $this->executor->fetchRecords(
            '('
            . 'SELECT id, external_id, start_date, date_added, drug, dosage, drug_dosage_instructions, active, '
            . 'NULL AS list_id, NULL AS list_external_id, NULL AS date, NULL AS begdate, NULL AS title, '
            . 'NULL AS activity, NULL AS lists_medication_id, '
            . '\'prescriptions\' AS source_table, '
            . 'COALESCE(start_date, date_added) AS medication_sort_date '
            . 'FROM prescriptions WHERE patient_id = ? AND active = ? '
            . 'ORDER BY COALESCE(start_date, date_added) DESC LIMIT ' . $limit
            . ') UNION ALL ('
            . 'SELECT NULL AS id, NULL AS external_id, NULL AS start_date, NULL AS date_added, '
            . 'NULL AS drug, NULL AS dosage, lm.drug_dosage_instructions, NULL AS active, '
            . 'l.id AS list_id, l.external_id AS list_external_id, l.date, l.begdate, l.title, '
            . 'l.activity, lm.id AS lists_medication_id, '
            . 'CASE WHEN lm.id IS NULL THEN \'lists\' ELSE \'lists_medication\' END AS source_table, '
            . 'COALESCE(l.begdate, l.date) AS medication_sort_date '
            . 'FROM lists l '
            . 'LEFT JOIN lists_medication lm ON lm.list_id = l.id '
            . 'WHERE l.pid = ? AND l.type = ? AND l.activity = ? '
            . 'ORDER BY COALESCE(l.begdate, l.date) DESC LIMIT ' . $limit
            . ') ORDER BY medication_sort_date DESC LIMIT ' . $limit,
            [$patientId->value, $activity, $patientId->value, 'medication', $activity],
            $deadline,
        );
    }

    public function activeAllergies(PatientId $patientId, int $limit, ?Deadline $deadline = null): array
    {
        return $this->executor->fetchRecords(
            'SELECT id, external_id, date, begdate, title, reaction, severity_al, verification, comments, activity '
            . 'FROM lists '
            . 'WHERE pid = ? AND type = ? AND activity = 1 '
            . 'ORDER BY COALESCE(begdate, date) DESC LIMIT ' . $this->limit($limit),
            [$patientId->value, 'allergy'],
            $deadline,
        );
    }

    public function recentLabs(PatientId $patientId, int $limit, ?Deadline $deadline = null): array
    {
        return $this->executor->fetchRecords(
            'SELECT pr.procedure_result_id, pr.comments, pr.result_text, pr.result, pr.units, pr.date, '
            . 'pr.result_code, poc.procedure_code '
            . 'FROM procedure_result pr '
            . 'INNER JOIN procedure_report rep ON rep.procedure_report_id = pr.procedure_report_id '
            . 'INNER JOIN procedure_order po ON po.procedure_order_id = rep.procedure_order_id '
            . 'LEFT JOIN procedure_order_code poc ON poc.procedure_order_id = po.procedure_order_id '
            . 'WHERE po.patient_id = ? '
            . 'ORDER BY pr.date DESC LIMIT ' . $this->limit($limit),
            [$patientId->value],
            $deadline,
        );
    }

    public function recentVitals(
        PatientId $patientId,
        int $limit,
        int $staleAfterDays,
        ?Deadline $deadline = null,
    ): array {
        return $this->executor->fetchRecords(
            'SELECT id, external_id, date, bps, bpd, weight, height, temperature, pulse, respiration, BMI, '
            . 'oxygen_saturation, activity, authorized '
            . 'FROM form_vitals '
            . 'WHERE pid = ? AND activity = 1 AND authorized = 1 '
            . 'AND date >= DATE_SUB(CURRENT_DATE, INTERVAL ' . $this->days($staleAfterDays) . ' DAY) '
            . 'ORDER BY date DESC LIMIT ' . $this->limit($limit),
            [$patientId->value],
            $deadline,
        );
    }

    public function staleVitals(
        PatientId $patientId,
        int $limit,
        int $staleAfterDays,
        ?Deadline $deadline = null,
    ): array {
        return $this->executor->fetchRecords(
            'SELECT id, external_id, date, bps, bpd, weight, height, temperature, pulse, respiration, BMI, '
            . 'oxygen_saturation, activity, authorized '
            . 'FROM form_vitals '
            . 'WHERE pid = ? AND activity = 1 AND authorized = 1 '
            . 'AND date < DATE_SUB(CURRENT_DATE, INTERVAL ' . $this->days($staleAfterDays) . ' DAY) '
            . 'ORDER BY date DESC LIMIT ' . $this->limit($limit),
            [$patientId->value],
            $deadline,
        );
    }

    public function recentEncounters(PatientId $patientId, int $limit, ?Deadline $deadline = null): array
    {
        return $this->executor->fetchRecords(
            'SELECT encounter, date AS encounter_date, reason '
            . 'FROM form_encounter '
            . 'WHERE pid = ? '
            . 'ORDER BY date DESC LIMIT ' . $this->limit($limit),
            [$patientId->value],
            $deadline,
        );
    }

    public function recentNotes(PatientId $patientId, int $limit, ?Deadline $deadline = null): array
    {
        return $this->executor->fetchRecords(
            'SELECT n.id, n.external_id, n.date AS note_date, n.codetext, n.description, '
            . 'n.activity, n.authorized, e.encounter, e.reason AS encounter_reason, e.date AS encounter_date '
            . 'FROM form_clinical_notes n '
            // CAST: form_clinical_notes.encounter is VARCHAR; form_encounter.encounter is numeric.
            . 'LEFT JOIN form_encounter e ON e.pid = n.pid AND CAST(e.encounter AS CHAR) = n.encounter '
            . 'WHERE n.pid = ? AND n.activity = 1 AND n.authorized = 1 '
            . 'ORDER BY COALESCE(n.date, e.date) DESC LIMIT ' . $this->limit($limit),
            [$patientId->value],
            $deadline,
        );
    }

    private function limit(int $limit): int
    {
        return max(1, min(50, $limit));
    }

    private function days(int $days): int
    {
        return max(1, min(3650, $days));
    }
}
