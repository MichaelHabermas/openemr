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

use OpenEMR\AgentForge\PatientId;
use OpenEMR\AgentForge\QueryExecutor;
use OpenEMR\AgentForge\SqlChartEvidenceRepository;
use PHPUnit\Framework\TestCase;

final class SqlChartEvidenceRepositoryIsolationTest extends TestCase
{
    public function testEveryEvidenceQueryIsScopedToRequestedPatient(): void
    {
        $executor = new RecordingQueryExecutor();
        $repository = new SqlChartEvidenceRepository($executor);
        $patientId = new PatientId(900001);

        $repository->demographics($patientId);
        $repository->activeProblems($patientId, 10);
        $repository->activePrescriptions($patientId, 10);
        $repository->recentLabs($patientId, 20);
        $repository->recentNotes($patientId, 5);

        $this->assertCount(5, $executor->queries);

        foreach ($executor->queries as $query) {
            $this->assertSame(900001, $query['binds'][0]);
            $this->assertStringContainsString('?', $query['sql']);
            $this->assertStringNotContainsString('UNION', strtoupper($query['sql']));
        }

        $this->assertStringContainsString('patient_data WHERE pid = ?', $executor->queries[0]['sql']);
        $this->assertStringContainsString('lists', $executor->queries[1]['sql']);
        $this->assertStringContainsString('WHERE pid = ?', $executor->queries[1]['sql']);
        $this->assertStringContainsString('prescriptions WHERE patient_id = ?', $executor->queries[2]['sql']);
        $this->assertStringContainsString('WHERE po.patient_id = ?', $executor->queries[3]['sql']);
        $this->assertStringContainsString('WHERE n.pid = ?', $executor->queries[4]['sql']);
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
