<?php

/**
 * Writes promoted lab results into OpenEMR's native procedure_* tables.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\AgentForge\Document\Promotion;

use OpenEMR\AgentForge\DatabaseExecutor;
use OpenEMR\AgentForge\Document\DocumentJob;
use OpenEMR\AgentForge\Document\Schema\LabResultRow;

final readonly class LabResultChartWriter
{
    public function __construct(
        private DatabaseExecutor $executor,
        private PromotionValueSerializer $serializer,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function existingChartLabMatch(DocumentJob $job, LabResultRow $row): array
    {
        $collectedDate = substr($row->collectedAt, 0, 10);
        if ($row->testName === '' || $row->value === '' || $collectedDate === '') {
            return [];
        }

        $rows = $this->executor->fetchRecords(
            'SELECT pr.procedure_result_id, pr.result, pr.units '
            . 'FROM procedure_result pr '
            . 'INNER JOIN procedure_report prep ON prep.procedure_report_id = pr.procedure_report_id '
            . 'INNER JOIN procedure_order po ON po.procedure_order_id = prep.procedure_order_id '
            . 'WHERE po.patient_id = ? '
            . 'AND pr.result_text = ? '
            . 'AND DATE(pr.date) = ? '
            . 'AND (pr.document_id IS NULL OR pr.document_id <> ?) '
            . 'LIMIT 1',
            [$job->patientId->value, $row->testName, $collectedDate, $job->documentId->value],
        );

        return $rows[0] ?? [];
    }

    /** @param array<string, mixed> $row */
    public function sameLabValue(array $row, LabResultRow $extracted): bool
    {
        return $this->serializer->normalizeScalar($this->serializer->nullableString($row, 'result')) === $this->serializer->normalizeScalar($extracted->value)
            && $this->serializer->normalizeScalar($this->serializer->nullableString($row, 'units')) === $this->serializer->normalizeScalar($extracted->unit);
    }

    public function writeLabResult(
        DocumentJob $job,
        LabResultRow $row,
        string $clinicalContentFingerprint,
        string $collectedAt,
        string $now,
    ): int {
        $orderId = $this->executor->insert(
            'INSERT INTO procedure_order '
            . '(provider_id, patient_id, date_collected, date_ordered, order_status, activity, control_id, history_order, procedure_order_type, order_intent) '
            . 'VALUES (?, ?, ?, ?, ?, 1, ?, ?, ?, ?)',
            [
                0,
                $job->patientId->value,
                $collectedAt,
                $now,
                'complete',
                sprintf('agentforge-doc-%d', $job->documentId->value),
                '1',
                'laboratory_test',
                'order',
            ],
        );
        $this->executor->executeStatement(
            'INSERT INTO procedure_order_code '
            . '(procedure_order_id, procedure_order_seq, procedure_code, procedure_name, procedure_source, procedure_order_title, procedure_type) '
            . 'VALUES (?, 1, ?, ?, ?, ?, ?)',
            [
                $orderId,
                substr('agentforge-' . $clinicalContentFingerprint, 0, 31),
                $row->testName,
                '1',
                $row->testName,
                'laboratory_test',
            ],
        );
        $reportId = $this->executor->insert(
            'INSERT INTO procedure_report '
            . '(procedure_order_id, procedure_order_seq, date_collected, date_report, source, specimen_num, report_status, review_status, report_notes) '
            . 'VALUES (?, 1, ?, ?, 0, ?, ?, ?, ?)',
            [
                $orderId,
                $collectedAt,
                $now,
                $job->id?->value,
                'complete',
                'reviewed',
                'AgentForge promoted from verified clinical document.',
            ],
        );

        return $this->executor->insert(
            'INSERT INTO procedure_result '
            . '(procedure_report_id, result_data_type, result_text, date, facility, units, result, `range`, abnormal, comments, document_id, result_status) '
            . 'VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [
                $reportId,
                is_numeric($row->value) ? 'N' : 'S',
                $row->testName,
                $collectedAt,
                'AgentForge Document Extraction',
                $row->unit,
                $row->value,
                $row->referenceRange,
                $row->abnormalFlag->value,
                sprintf('agentforge-fact:%s', $clinicalContentFingerprint),
                $job->documentId->value,
                'final',
            ],
        );
    }
}
