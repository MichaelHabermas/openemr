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

final readonly class SqlChartEvidenceRepository implements ChartEvidenceRepository
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

    public function activeMedications(PatientId $patientId, int $limit): array
    {
        $limit = $this->limit($limit);
        $prescriptions = $this->executor->fetchRecords(
            'SELECT id, external_id, start_date, date_added, drug, drug_dosage_instructions, active, '
            . '\'prescriptions\' AS source_table '
            . 'FROM prescriptions WHERE patient_id = ? AND active = 1 '
            . 'ORDER BY COALESCE(start_date, date_added) DESC LIMIT ' . $limit,
            [$patientId->value],
        );

        $listMedications = $this->executor->fetchRecords(
            'SELECT l.id AS list_id, l.external_id AS list_external_id, l.date, l.begdate, l.title, '
            . 'l.activity, lm.id AS lists_medication_id, lm.drug_dosage_instructions, '
            . 'lm.usage_category_title, lm.request_intent_title, '
            . 'CASE WHEN lm.id IS NULL THEN \'lists\' ELSE \'lists_medication\' END AS source_table '
            . 'FROM lists l '
            . 'LEFT JOIN lists_medication lm ON lm.list_id = l.id '
            . 'WHERE l.pid = ? AND l.type = ? AND l.activity = 1 '
            . 'ORDER BY COALESCE(l.begdate, l.date) DESC LIMIT ' . $limit,
            [$patientId->value, 'medication'],
        );

        $medications = array_merge($prescriptions, $listMedications);
        usort(
            $medications,
            static fn (array $left, array $right): int => strcmp(
                self::medicationSortDate($right),
                self::medicationSortDate($left),
            ),
        );

        return array_slice($medications, 0, $limit);
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

    /** @param array<string, mixed> $row */
    private static function medicationSortDate(array $row): string
    {
        return EvidenceRowValue::dateOnly($row, 'start_date', 'date_added', 'begdate', 'date');
    }
}
