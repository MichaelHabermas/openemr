<?php

/**
 * SQL-backed read-only chart evidence repository.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\AgentForge;

use OpenEMR\Common\Database\QueryUtils;

final class SqlChartEvidenceRepository implements ChartEvidenceRepository
{
    public function demographics(PatientId $patientId): ?array
    {
        $records = QueryUtils::fetchRecords(
            'SELECT pid, fname, lname, DOB, sex, date FROM patient_data WHERE pid = ? LIMIT 1',
            [$patientId->value],
        );

        return $records[0] ?? null;
    }

    public function activeProblems(PatientId $patientId, int $limit): array
    {
        return QueryUtils::fetchRecords(
            'SELECT id, external_id, date, title, begdate FROM lists '
            . 'WHERE pid = ? AND type = ? AND activity = 1 '
            . 'ORDER BY COALESCE(begdate, date) DESC LIMIT ' . $this->limit($limit),
            [$patientId->value, 'medical_problem'],
        );
    }

    public function activePrescriptions(PatientId $patientId, int $limit): array
    {
        return QueryUtils::fetchRecords(
            'SELECT id, external_id, start_date, date_added, drug, drug_dosage_instructions '
            . 'FROM prescriptions WHERE patient_id = ? AND active = 1 '
            . 'ORDER BY COALESCE(start_date, date_added) DESC LIMIT ' . $this->limit($limit),
            [$patientId->value],
        );
    }

    public function recentLabs(PatientId $patientId, int $limit): array
    {
        return QueryUtils::fetchRecords(
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
        return QueryUtils::fetchRecords(
            'SELECT n.id, n.external_id, n.date AS note_date, n.codetext, n.description, e.date AS encounter_date '
            . 'FROM form_clinical_notes n '
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
