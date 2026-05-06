<?php

/**
 * Isolated tests for AgentForge SQL evidence repository scoping.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Tests\Isolated\AgentForge;

use OpenEMR\AgentForge\Auth\PatientId;
use OpenEMR\AgentForge\Evidence\SqlChartEvidenceRepository;
use OpenEMR\Tests\Isolated\AgentForge\Support\FakeDatabaseExecutor;
use PHPUnit\Framework\TestCase;

final class SqlChartEvidenceRepositoryIsolationTest extends TestCase
{
    public function testEveryEvidenceQueryIsScopedToRequestedPatient(): void
    {
        $executor = new FakeDatabaseExecutor();
        $repository = new SqlChartEvidenceRepository($executor);
        $patientId = new PatientId(900001);

        $repository->demographics($patientId);
        $repository->recentEncounters($patientId, 5);
        $repository->activeProblems($patientId, 10);
        $repository->activeMedications($patientId, 10);
        $repository->inactiveMedications($patientId, 10);
        $repository->activeAllergies($patientId, 10);
        $repository->recentLabs($patientId, 20);
        $repository->recentVitals($patientId, 3, 180);
        $repository->staleVitals($patientId, 1, 180);
        $repository->recentNotes($patientId, 5);

        $this->assertCount(10, $executor->reads);

        foreach ($executor->reads as $query) {
            $this->assertSame(900001, $query['binds'][0]);
            $this->assertStringContainsString('?', $query['sql']);
        }

        $this->assertStringContainsString('patient_data WHERE pid = ?', $executor->reads[0]['sql']);
        $this->assertStringContainsString('FROM form_encounter', $executor->reads[1]['sql']);
        $this->assertStringContainsString('WHERE pid = ?', $executor->reads[1]['sql']);
        $this->assertStringContainsString('lists', $executor->reads[2]['sql']);
        $this->assertStringContainsString('diagnosis', $executor->reads[2]['sql']);
        $this->assertStringContainsString('WHERE pid = ?', $executor->reads[2]['sql']);
        $this->assertStringNotContainsString('UNION', strtoupper($executor->reads[2]['sql']));

        $this->assertStringContainsString('UNION ALL', $executor->reads[3]['sql']);
        $this->assertStringContainsString('FROM prescriptions WHERE patient_id = ?', $executor->reads[3]['sql']);
        $this->assertStringContainsString('FROM lists l', $executor->reads[3]['sql']);
        $this->assertStringContainsString('LEFT JOIN lists_medication lm ON lm.list_id = l.id', $executor->reads[3]['sql']);
        $this->assertStringContainsString('WHERE l.pid = ?', $executor->reads[3]['sql']);
        $this->assertSame([900001, 1, 900001, 'medication', 1], $executor->reads[3]['binds']);

        $this->assertStringContainsString('UNION ALL', $executor->reads[4]['sql']);
        $this->assertStringContainsString('FROM prescriptions WHERE patient_id = ?', $executor->reads[4]['sql']);
        $this->assertStringContainsString('FROM lists l', $executor->reads[4]['sql']);
        $this->assertSame([900001, 0, 900001, 'medication', 0], $executor->reads[4]['binds']);

        $this->assertStringContainsString('FROM lists', $executor->reads[5]['sql']);
        $this->assertStringContainsString('WHERE pid = ?', $executor->reads[5]['sql']);
        $this->assertStringContainsString('activity = 1', $executor->reads[5]['sql']);
        $this->assertSame([900001, 'allergy'], $executor->reads[5]['binds']);
        $this->assertStringNotContainsString('UNION', strtoupper($executor->reads[5]['sql']));

        $this->assertStringContainsString('WHERE po.patient_id = ?', $executor->reads[6]['sql']);
        $this->assertStringContainsString('pr.result_code', $executor->reads[6]['sql']);
        $this->assertStringContainsString('poc.procedure_code', $executor->reads[6]['sql']);
        $this->assertStringContainsString('LEFT JOIN documents source_doc ON source_doc.id = pr.document_id', $executor->reads[6]['sql']);
        $this->assertStringContainsString('source_doc.id IS NOT NULL', $executor->reads[6]['sql']);
        $this->assertStringContainsString('source_doc.deleted IS NULL OR source_doc.deleted = 0', $executor->reads[6]['sql']);
        $this->assertStringContainsString('NOT EXISTS', $executor->reads[6]['sql']);
        $this->assertStringContainsString('FROM clinical_document_promotions cdp', $executor->reads[6]['sql']);
        $this->assertStringContainsString('LEFT JOIN clinical_document_processing_jobs cdpj ON cdpj.id = cdp.job_id', $executor->reads[6]['sql']);
        $this->assertStringContainsString('LEFT JOIN documents cdp_source_doc ON cdp_source_doc.id = cdp.document_id', $executor->reads[6]['sql']);
        $this->assertStringContainsString('cdp.active <> 1', $executor->reads[6]['sql']);
        $this->assertStringContainsString('cdp.retracted_at IS NOT NULL', $executor->reads[6]['sql']);
        $this->assertStringContainsString('cdpj.retracted_at IS NOT NULL', $executor->reads[6]['sql']);
        $this->assertStringContainsString('cdp_source_doc.id IS NULL', $executor->reads[6]['sql']);
        $this->assertStringContainsString('cdp_source_doc.deleted IS NOT NULL AND cdp_source_doc.deleted <> 0', $executor->reads[6]['sql']);
        $this->assertSame([900001, 'procedure_result'], $executor->reads[6]['binds']);
        $this->assertStringNotContainsString('UNION', strtoupper($executor->reads[6]['sql']));

        $this->assertStringContainsString('FROM form_vitals', $executor->reads[7]['sql']);
        $this->assertStringContainsString('WHERE pid = ?', $executor->reads[7]['sql']);
        $this->assertStringContainsString('activity = 1', $executor->reads[7]['sql']);
        $this->assertStringContainsString('authorized = 1', $executor->reads[7]['sql']);
        $this->assertStringContainsString('date >= DATE_SUB(CURRENT_DATE, INTERVAL 180 DAY)', $executor->reads[7]['sql']);
        $this->assertStringNotContainsString('UNION', strtoupper($executor->reads[7]['sql']));

        $this->assertStringContainsString('date < DATE_SUB(CURRENT_DATE, INTERVAL 180 DAY)', $executor->reads[8]['sql']);
        $this->assertStringNotContainsString('UNION', strtoupper($executor->reads[8]['sql']));

        $this->assertStringContainsString('WHERE n.pid = ?', $executor->reads[9]['sql']);
        $this->assertStringNotContainsString('UNION', strtoupper($executor->reads[9]['sql']));
    }

    public function testActiveMedicationsReturnsTotalLimitInDateOrderAcrossSources(): void
    {
        $repository = new SqlChartEvidenceRepository(new MedicationRowsQueryExecutor());

        $rows = $repository->activeMedications(new PatientId(900001), 2);

        $this->assertCount(2, $rows);
        $this->assertSame('list-newest', $rows[0]['title']);
        $this->assertSame('rx-middle', $rows[1]['drug']);
    }
}

final class MedicationRowsQueryExecutor implements \OpenEMR\AgentForge\DatabaseExecutor
{
    public function fetchRecords(string $sql, array $binds = [], ?\OpenEMR\AgentForge\Deadline $deadline = null): array
    {
        if (str_contains($sql, 'UNION ALL')) {
            return [
                [
                    'list_id' => 3,
                    'begdate' => '2026-05-01',
                    'title' => 'list-newest',
                    'activity' => 1,
                    'source_table' => 'lists',
                    'medication_sort_date' => '2026-05-01',
                ],
                [
                    'id' => 2,
                    'start_date' => '2026-04-01',
                    'drug' => 'rx-middle',
                    'active' => 1,
                    'source_table' => 'prescriptions',
                    'medication_sort_date' => '2026-04-01',
                ],
            ];
        }

        return [];
    }

    public function executeStatement(string $sql, array $binds = []): void
    {
    }

    public function executeAffected(string $sql, array $binds = []): int
    {
        return 0;
    }

    public function insert(string $sql, array $binds = []): int
    {
        return 0;
    }
}
