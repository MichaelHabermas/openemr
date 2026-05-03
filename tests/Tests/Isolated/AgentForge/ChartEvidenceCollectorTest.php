<?php

/**
 * Isolated tests for AgentForge chart evidence collection boundary.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Tests\Isolated\AgentForge;

use OpenEMR\AgentForge\AgentForgeClock;
use OpenEMR\AgentForge\Auth\PatientId;
use OpenEMR\AgentForge\Deadline;
use OpenEMR\AgentForge\Evidence\ChartEvidenceTool;
use OpenEMR\AgentForge\Evidence\ChartQuestionPlan;
use OpenEMR\AgentForge\Evidence\EvidenceItem;
use OpenEMR\AgentForge\Evidence\EvidenceResult;
use OpenEMR\AgentForge\Evidence\SerialChartEvidenceCollector;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class ChartEvidenceCollectorTest extends TestCase
{
    public function testCollectsOnlySelectedSectionsIntoPromptSafeBundle(): void
    {
        $labs = new CollectorRecordingTool('Recent labs', [
            new EvidenceItem('lab', 'procedure_result', 'a1c', '2026-04-10', 'Hemoglobin A1c', '7.4 %'),
        ]);
        $meds = new CollectorRecordingTool('Active medications', [
            new EvidenceItem('medication', 'prescriptions', 'metformin', '2026-03-15', 'Metformin ER 500 mg', 'daily'),
        ]);

        $run = (new SerialChartEvidenceCollector([$labs, $meds]))->collect(
            new PatientId(900001),
            new ChartQuestionPlan('lab', ['Recent labs'], 8000, skippedSections: ['Active medications']),
        );

        $this->assertTrue($labs->called);
        $this->assertFalse($meds->called);
        $this->assertSame(['Recent labs'], $run->toolsCalled);
        $this->assertSame(['Active medications'], $run->skippedSections);
        $this->assertSame(['lab:procedure_result/a1c@2026-04-10'], $run->bundle->sourceIds());
        $this->assertArrayNotHasKey('source_table', $run->bundle->toPromptArray()['evidence'][0]);
    }

    public function testToolFailureIsSanitizedAndPreservedAsFailedSection(): void
    {
        $run = (new SerialChartEvidenceCollector([new CollectorThrowingTool()]))->collect(
            new PatientId(900001),
            new ChartQuestionPlan('lab', ['Recent labs'], 8000),
        );

        $this->assertSame(['Recent labs'], $run->toolsCalled);
        $this->assertSame(['Recent labs could not be checked.'], $run->bundle->failedSections);
    }

    public function testDeadlineStopsLaterSectionsAndPreservesPartialEvidence(): void
    {
        $later = new CollectorRecordingTool('Active medications', [
            new EvidenceItem('medication', 'prescriptions', 'metformin', '2026-03-15', 'Metformin ER 500 mg', 'daily'),
        ]);
        $run = (new SerialChartEvidenceCollector([
            new CollectorRecordingTool('Recent labs', [
                new EvidenceItem('lab', 'procedure_result', 'a1c', '2026-04-10', 'Hemoglobin A1c', '7.4 %'),
            ]),
            $later,
        ], clock: new CollectorManualClock([0, 50])))->collect(
            new PatientId(900001),
            new ChartQuestionPlan('visit_briefing', ['Recent labs', 'Active medications'], 10),
        );

        $this->assertFalse($later->called);
        $this->assertSame(['lab:procedure_result/a1c@2026-04-10'], $run->bundle->sourceIds());
        $this->assertContains('Some chart sections could not be checked before the deadline.', $run->bundle->failedSections);
    }
}

final class CollectorRecordingTool implements ChartEvidenceTool
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

final class CollectorThrowingTool implements ChartEvidenceTool
{
    public function section(): string
    {
        return 'Recent labs';
    }

    public function collect(PatientId $patientId, ?Deadline $deadline = null): EvidenceResult
    {
        throw new RuntimeException('SQLSTATE hidden internals');
    }
}

final class CollectorManualClock implements AgentForgeClock
{
    /** @param list<int> $ticks */
    public function __construct(private array $ticks)
    {
    }

    public function nowMs(): int
    {
        if ($this->ticks === []) {
            return 0;
        }

        return array_shift($this->ticks);
    }
}
