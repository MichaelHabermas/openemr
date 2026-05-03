<?php

/**
 * Isolated tests for the prefetch-coordinating concurrent evidence collector.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Tests\Isolated\AgentForge;

use OpenEMR\AgentForge\Auth\PatientId;
use OpenEMR\AgentForge\Deadline;
use OpenEMR\AgentForge\Evidence\ChartEvidenceTool;
use OpenEMR\AgentForge\Evidence\ChartQuestionPlan;
use OpenEMR\AgentForge\Evidence\ConcurrentChartEvidenceCollector;
use OpenEMR\AgentForge\Evidence\EvidenceItem;
use OpenEMR\AgentForge\Evidence\EvidenceResult;
use OpenEMR\AgentForge\Evidence\PrefetchableChartEvidenceRepository;
use PHPUnit\Framework\TestCase;

final class ConcurrentChartEvidenceCollectorTest extends TestCase
{
    public function testPrefetchesOncePerCollectCycleWithPlannerSections(): void
    {
        $prefetcher = new RecordingPrefetchRepository();
        $labs = new ConcurrentRecordingTool('Recent labs', [
            new EvidenceItem('lab', 'procedure_result', 'a1c', '2026-04-10', 'Hemoglobin A1c', '7.4 %'),
        ]);
        $meds = new ConcurrentRecordingTool('Active medications', [
            new EvidenceItem('medication', 'prescriptions', 'metformin', '2026-03-15', 'Metformin ER 500 mg', 'daily'),
        ]);

        $collector = new ConcurrentChartEvidenceCollector([$labs, $meds], $prefetcher);

        $collector->collect(
            new PatientId(900001),
            new ChartQuestionPlan('visit_briefing', ['Recent labs', 'Active medications'], 8000),
        );

        self::assertSame(1, $prefetcher->callCount, 'prefetch should run exactly once per collect cycle');
        self::assertSame(['Recent labs', 'Active medications'], $prefetcher->lastSections);
        self::assertSame(900001, $prefetcher->lastPatientId?->value);
    }

    public function testSkipsPrefetchWhenPlanHasNoSections(): void
    {
        $prefetcher = new RecordingPrefetchRepository();
        $collector = new ConcurrentChartEvidenceCollector([], $prefetcher);

        $collector->collect(
            new PatientId(900001),
            new ChartQuestionPlan('lab', [], 8000),
        );

        self::assertSame(0, $prefetcher->callCount);
    }

    public function testWorksWithoutPrefetcherAndYieldsSerialEquivalentBundle(): void
    {
        $labs = new ConcurrentRecordingTool('Recent labs', [
            new EvidenceItem('lab', 'procedure_result', 'a1c', '2026-04-10', 'Hemoglobin A1c', '7.4 %'),
        ]);

        $run = (new ConcurrentChartEvidenceCollector([$labs]))->collect(
            new PatientId(900001),
            new ChartQuestionPlan('lab', ['Recent labs'], 8000),
        );

        self::assertTrue($labs->called);
        self::assertSame(['Recent labs'], $run->toolsCalled);
        self::assertSame(['lab:procedure_result/a1c@2026-04-10'], $run->bundle->sourceIds());
    }
}

final class ConcurrentRecordingTool implements ChartEvidenceTool
{
    public bool $called = false;

    /** @param list<EvidenceItem> $items */
    public function __construct(private readonly string $section, private readonly array $items)
    {
    }

    public function section(): string
    {
        return $this->section;
    }

    public function collect(PatientId $patientId, ?Deadline $deadline = null): EvidenceResult
    {
        $this->called = true;

        return EvidenceResult::found($this->section, $this->items);
    }
}

final class RecordingPrefetchRepository implements PrefetchableChartEvidenceRepository
{
    public int $callCount = 0;
    public ?PatientId $lastPatientId = null;
    /** @var list<string> */
    public array $lastSections = [];

    public function prefetch(PatientId $patientId, array $sections, ?Deadline $deadline = null): void
    {
        $this->callCount++;
        $this->lastPatientId = $patientId;
        $this->lastSections = $sections;
    }

    public function demographics(PatientId $patientId, ?Deadline $deadline = null): ?array
    {
        return null;
    }

    public function activeProblems(PatientId $patientId, int $limit, ?Deadline $deadline = null): array
    {
        return [];
    }

    public function activeMedications(PatientId $patientId, int $limit, ?Deadline $deadline = null): array
    {
        return [];
    }

    public function inactiveMedications(PatientId $patientId, int $limit, ?Deadline $deadline = null): array
    {
        return [];
    }

    public function activeAllergies(PatientId $patientId, int $limit, ?Deadline $deadline = null): array
    {
        return [];
    }

    public function recentLabs(PatientId $patientId, int $limit, ?Deadline $deadline = null): array
    {
        return [];
    }

    public function recentVitals(
        PatientId $patientId,
        int $limit,
        int $staleAfterDays,
        ?Deadline $deadline = null,
    ): array {
        return [];
    }

    public function staleVitals(
        PatientId $patientId,
        int $limit,
        int $staleAfterDays,
        ?Deadline $deadline = null,
    ): array {
        return [];
    }

    public function recentEncounters(PatientId $patientId, int $limit, ?Deadline $deadline = null): array
    {
        return [];
    }

    public function recentNotes(PatientId $patientId, int $limit, ?Deadline $deadline = null): array
    {
        return [];
    }
}
