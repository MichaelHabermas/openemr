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
use OpenEMR\AgentForge\QueryExecutor;
use PHPUnit\Framework\TestCase;

final class SqlChartEvidenceRepositoryIsolationTest extends TestCase
{
    public function testEveryEvidenceQueryIsScopedToRequestedPatient(): void
    {
        $executor = new RecordingQueryExecutor();
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

        $this->assertCount(12, $executor->queries);

        foreach ($executor->queries as $query) {
            $this->assertSame(900001, $query['binds'][0]);
            $this->assertStringContainsString('?', $query['sql']);
            $this->assertStringNotContainsString('UNION', strtoupper($query['sql']));
        }

        $this->assertStringContainsString('patient_data WHERE pid = ?', $executor->queries[0]['sql']);
        $this->assertStringContainsString('FROM form_encounter', $executor->queries[1]['sql']);
        $this->assertStringContainsString('WHERE pid = ?', $executor->queries[1]['sql']);
        $this->assertStringContainsString('lists', $executor->queries[2]['sql']);
        $this->assertStringContainsString('diagnosis', $executor->queries[2]['sql']);
        $this->assertStringContainsString('WHERE pid = ?', $executor->queries[2]['sql']);
        $this->assertStringContainsString('prescriptions WHERE patient_id = ?', $executor->queries[3]['sql']);
        $this->assertStringContainsString('active = 1', $executor->queries[3]['sql']);
        $this->assertStringContainsString('FROM lists l', $executor->queries[4]['sql']);
        $this->assertStringContainsString('LEFT JOIN lists_medication lm ON lm.list_id = l.id', $executor->queries[4]['sql']);
        $this->assertStringContainsString('WHERE l.pid = ?', $executor->queries[4]['sql']);
        $this->assertStringContainsString('l.activity = 1', $executor->queries[4]['sql']);
        $this->assertStringContainsString('prescriptions WHERE patient_id = ?', $executor->queries[5]['sql']);
        $this->assertStringContainsString('active = 0', $executor->queries[5]['sql']);
        $this->assertStringContainsString('FROM lists l', $executor->queries[6]['sql']);
        $this->assertStringContainsString('l.activity = 0', $executor->queries[6]['sql']);
        $this->assertSame([900001, 'medication'], $executor->queries[6]['binds']);
        $this->assertStringContainsString('FROM lists', $executor->queries[7]['sql']);
        $this->assertStringContainsString('WHERE pid = ?', $executor->queries[7]['sql']);
        $this->assertStringContainsString('activity = 1', $executor->queries[7]['sql']);
        $this->assertSame([900001, 'allergy'], $executor->queries[7]['binds']);
        $this->assertStringContainsString('WHERE po.patient_id = ?', $executor->queries[8]['sql']);
        $this->assertStringContainsString('pr.result_code', $executor->queries[8]['sql']);
        $this->assertStringContainsString('poc.procedure_code', $executor->queries[8]['sql']);
        $this->assertStringContainsString('FROM form_vitals', $executor->queries[9]['sql']);
        $this->assertStringContainsString('WHERE pid = ?', $executor->queries[9]['sql']);
        $this->assertStringContainsString('activity = 1', $executor->queries[9]['sql']);
        $this->assertStringContainsString('authorized = 1', $executor->queries[9]['sql']);
        $this->assertStringContainsString('date >= DATE_SUB(CURRENT_DATE, INTERVAL 180 DAY)', $executor->queries[9]['sql']);
        $this->assertStringContainsString('date < DATE_SUB(CURRENT_DATE, INTERVAL 180 DAY)', $executor->queries[10]['sql']);
        $this->assertStringContainsString('WHERE n.pid = ?', $executor->queries[11]['sql']);
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

final class RecordingQueryExecutor implements QueryExecutor
{
    /** @var list<array{sql: string, binds: list<mixed>}> */
    public array $queries = [];

    public function fetchRecords(string $sql, array $binds = []): array
    {
        $this->queries[] = [
            'sql' => $sql,
            'binds' => $binds,
        ];

        return [];
    }
}

final class MedicationRowsQueryExecutor implements QueryExecutor
{
    public function fetchRecords(string $sql, array $binds = []): array
    {
        if (str_contains($sql, 'FROM prescriptions')) {
            return [
                [
                    'id' => 1,
                    'start_date' => '2026-02-01',
                    'drug' => 'rx-oldest',
                    'active' => 1,
                    'source_table' => 'prescriptions',
                ],
                [
                    'id' => 2,
                    'start_date' => '2026-04-01',
                    'drug' => 'rx-middle',
                    'active' => 1,
                    'source_table' => 'prescriptions',
                ],
            ];
        }

        if (str_contains($sql, 'FROM lists l')) {
            return [
                [
                    'list_id' => 3,
                    'begdate' => '2026-05-01',
                    'title' => 'list-newest',
                    'activity' => 1,
                    'source_table' => 'lists',
                ],
            ];
        }

        return [];
    }
}
