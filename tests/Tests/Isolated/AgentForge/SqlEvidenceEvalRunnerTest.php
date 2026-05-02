<?php

/**
 * Isolated tests for the seeded SQL evidence eval runner.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Tests\Isolated\AgentForge;

use DateTimeImmutable;
use OpenEMR\AgentForge\Auth\PatientAccessRepository;
use OpenEMR\AgentForge\Auth\PatientAuthorizationGate;
use OpenEMR\AgentForge\Auth\PatientId;
use OpenEMR\AgentForge\Eval\SqlEvidenceEvalCase;
use OpenEMR\AgentForge\Eval\SqlEvidenceEvalCaseRepository;
use OpenEMR\AgentForge\Eval\SqlEvidenceEvalRunner;
use OpenEMR\AgentForge\Evidence\ChartEvidenceTool;
use OpenEMR\AgentForge\Evidence\EvidenceItem;
use OpenEMR\AgentForge\Evidence\EvidenceResult;
use PHPUnit\Framework\TestCase;

final class SqlEvidenceEvalRunnerTest extends TestCase
{
    public function testCaseRepositoryDerivesRequiredCasesFromGroundTruth(): void
    {
        $repository = new SqlEvidenceEvalCaseRepository();
        $path = dirname(__DIR__, 4) . '/agent-forge/fixtures/demo-patient-ground-truth.json';

        $cases = $repository->load($path);

        $this->assertSame('2026-05-02-epic22', $repository->fixtureVersion($path));
        $this->assertSame(
            [
                'visit_briefing_900001',
                'missing_microalbumin_900001',
                'polypharmacy_900002',
                'sparse_chart_900003',
            ],
            array_map(static fn (SqlEvidenceEvalCase $case): string => $case->id, $cases),
        );
        $this->assertContains(
            'lab:procedure_result/agentforge-a1c-2026-04@2026-04-10',
            $cases[0]->expectedCitations,
        );
        $this->assertContains(
            'encounter:form_encounter/900415@2026-04-15',
            $cases[0]->expectedCitations,
        );
        $this->assertContains('Recent vitals', $cases[2]->expectedMissing);
        $this->assertContains(
            'medication:prescriptions/af-rx-p2-warfarin@2025-11-20',
            $cases[2]->expectedCitations,
        );
        $this->assertContains(
            'vital:form_vitals/af-vit-900003-stale-stale-blood-pressure@2024-01-10',
            $cases[3]->expectedCitations,
        );
        $this->assertNotContains('af-vit-900003-stale', $cases[3]->forbiddenCitations);
    }

    public function testRunnerPassesWhenExpectedEvidenceAndMissingSignalsArePresent(): void
    {
        $runner = new SqlEvidenceEvalRunner([
            new StaticSqlEvalTool('Recent labs', EvidenceResult::found('Recent labs', [
                new EvidenceItem('lab', 'procedure_result', 'agentforge-a1c-2026-04', '2026-04-10', 'Hemoglobin A1c', '7.4 %'),
            ])),
            new StaticSqlEvalTool('Recent vitals', EvidenceResult::missing('Recent vitals', 'Recent vitals not found in the chart within 180 days.')),
        ]);

        $summary = $runner->run([
            new SqlEvidenceEvalCase(
                'a1c_and_missing_vitals',
                900001,
                'A1c evidence and missing vitals signal.',
                ['lab:procedure_result/agentforge-a1c-2026-04@2026-04-10'],
                ['Recent vitals'],
                expectedValueFragments: ['7.4 %'],
            ),
        ], 'fixture-v1', 'abc123', 'isolated', new DateTimeImmutable('2026-05-02T12:00:00+00:00'));

        $this->assertSame('seeded_sql_evidence', $summary['tier']);
        $this->assertSame('fixture-v1', $summary['fixture_version']);
        $this->assertSame('abc123', $summary['code_version']);
        $this->assertSame('isolated', $summary['environment_label']);
        $this->assertSame(1, $summary['passed']);
        $this->assertSame(0, $summary['failed']);
        $this->assertTrue($summary['results'][0]['passed']);
    }

    public function testRunnerFailsOnMissingExpectedAndForbiddenEvidence(): void
    {
        $runner = new SqlEvidenceEvalRunner([
            new StaticSqlEvalTool('Active medications', EvidenceResult::found('Active medications', [
                new EvidenceItem('medication', 'prescriptions', 'af-rx-p2-warfarin', '2025-11-20', 'Warfarin 2 mg', 'inactive stale row'),
            ])),
        ]);

        $summary = $runner->run([
            new SqlEvidenceEvalCase(
                'inactive_medication_exclusion',
                900002,
                'Inactive medications must not be promoted.',
                ['medication:prescriptions/af-rx-p2-apixaban@2026-05-16'],
                forbiddenCitations: ['af-rx-p2-warfarin'],
                forbiddenValueFragments: ['Warfarin 2 mg'],
            ),
        ], 'fixture-v1', 'abc123', 'isolated', new DateTimeImmutable('2026-05-02T12:00:00+00:00'));

        $this->assertSame(0, $summary['passed']);
        $this->assertSame(1, $summary['failed']);
        $this->assertStringContainsString('Missing expected citation', $summary['results'][0]['failure_reason']);
        $this->assertStringContainsString('Found forbidden citation/source af-rx-p2-warfarin', $summary['results'][0]['failure_reason']);
        $this->assertStringContainsString('Found forbidden evidence value fragment "Warfarin 2 mg"', $summary['results'][0]['failure_reason']);
    }

    public function testRunnerCanIncludeSqlAuthorizationCases(): void
    {
        $runner = new SqlEvidenceEvalRunner([], new PatientAuthorizationGate(new StaticSqlEvalAccessRepository()));

        $summary = $runner->run([], 'fixture-v1', 'abc123', 'isolated', new DateTimeImmutable('2026-05-02T12:00:00+00:00'));

        $this->assertSame(3, $summary['total']);
        $this->assertSame(3, $summary['passed']);
        $this->assertSame(
            [
                'authorized_relationship_900001',
                'unauthorized_patient_900001',
                'cross_patient_mismatch_900001_900002',
            ],
            array_map(static fn (array $result): string => $result['id'], $summary['results']),
        );
    }
}

final readonly class StaticSqlEvalTool implements ChartEvidenceTool
{
    public function __construct(private string $section, private EvidenceResult $result)
    {
    }

    public function section(): string
    {
        return $this->section;
    }

    public function collect(PatientId $patientId): EvidenceResult
    {
        return $this->result;
    }
}

final class StaticSqlEvalAccessRepository implements PatientAccessRepository
{
    public function patientExists(PatientId $patientId): bool
    {
        return in_array($patientId->value, [900001, 900002], true);
    }

    public function userHasDirectRelationship(PatientId $patientId, int $userId): bool
    {
        return $patientId->value === 900001 && $userId === 1;
    }
}
