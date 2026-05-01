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
use OpenEMR\AgentForge\DefaultQueryExecutor;
use OpenEMR\AgentForge\QueryExecutor;

final class SqlChartEvidenceRepository implements ChartEvidenceRepository
{
    private QueryExecutor $executor;

    public function __construct(?QueryExecutor $executor = null)
    {
        $this->executor = $executor ?? new DefaultQueryExecutor();
    }

    public function demographics(PatientId $patientId): ?array
    {
        $records = $this->executor->fetchRecords(
            'SELECT pid, fname, lname, DOB, sex, date FROM patient_data WHERE pid = ? LIMIT 1',
            [$patientId->value],
        );

        return $records[0] ?? null;
    }

    public function activeProblems(PatientId $patientId, int $limit): array
    {
        return $this->executor->fetchRecords(
            'SELECT id, external_id, date, title, begdate, activity FROM lists '
            . 'WHERE pid = ? AND type = ? AND activity = 1 '
            . 'ORDER BY COALESCE(begdate, date) DESC LIMIT ' . $this->limit($limit),
            [$patientId->value, 'medical_problem'],
        );
    }

    public function activePrescriptions(PatientId $patientId, int $limit): array
    {
        return $this->executor->fetchRecords(
            'SELECT id, external_id, start_date, date_added, drug, drug_dosage_instructions, active '
            . 'FROM prescriptions WHERE patient_id = ? AND active = 1 '
            . 'ORDER BY COALESCE(start_date, date_added) DESC LIMIT ' . $this->limit($limit),
            [$patientId->value],
        );
    }

    public function recentLabs(PatientId $patientId, int $limit): array
    {
        return $this->executor->fetchRecords(
            'SELECT pr.procedure_result_id, pr.comments, pr.result_text, pr.result, pr.units, pr.date '
            . 'FROM procedure_result pr '
            . 'INNER JOIN procedure_report rep ON rep.procedure_report_id = pr.procedure_report_id '
            . 'INNER JOIN procedure_order po ON po.procedure_order_id = rep.procedure_order_id '
            . 'WHERE po.patient_id = ? '
            . 'ORDER BY pr.date DESC LIMIT ' . $this->limit($limit),
            [$patientId->value],
        );
    }

    public function recentNotes(PatientId $patientId, int $limit): array
    {
        return $this->executor->fetchRecords(
            'SELECT n.id, n.external_id, n.date AS note_date, n.codetext, n.description, '
            . 'n.activity, n.authorized, e.date AS encounter_date '
            . 'FROM form_clinical_notes n '
            // CAST: form_clinical_notes.encounter is VARCHAR; form_encounter.encounter is numeric.
            . 'LEFT JOIN form_encounter e ON e.pid = n.pid AND CAST(e.encounter AS CHAR) = n.encounter '
            . 'WHERE n.pid = ? AND n.activity = 1 AND n.authorized = 1 '
            . 'ORDER BY COALESCE(n.date, e.date) DESC LIMIT ' . $this->limit($limit),
            [$patientId->value],
        );
    }

    private function limit(int $limit): int
    {
        return max(1, min(50, $limit));
    }
}
