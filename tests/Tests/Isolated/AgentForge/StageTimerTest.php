<?php

/**
 * Isolated tests for AgentForge StageTimer.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Tests\Isolated\AgentForge;

use OpenEMR\AgentForge\AgentForgeClock;
use OpenEMR\AgentForge\StageTimer;
use PHPUnit\Framework\TestCase;

final class StageTimerTest extends TestCase
{
    public function testTimingsAreEmptyBeforeAnyStageRecorded(): void
    {
        $timer = new StageTimer(new StageTimerFakeClock());

        self::assertSame([], $timer->timings());
    }

    public function testStartAndStopRecordsElapsedMilliseconds(): void
    {
        $clock = new StageTimerFakeClock(1000);
        $timer = new StageTimer($clock);

        $timer->start('evidence');
        $clock->advance(75);
        $timer->stop('evidence');

        self::assertSame(['evidence' => 75], $timer->timings());
    }

    public function testStopWithoutPriorStartIsIgnored(): void
    {
        $timer = new StageTimer(new StageTimerFakeClock());

        $timer->stop('evidence');

        self::assertSame([], $timer->timings());
    }

    public function testMultipleStagesAreTrackedIndependently(): void
    {
        $clock = new StageTimerFakeClock(0);
        $timer = new StageTimer($clock);

        $timer->start('evidence');
        $clock->advance(40);
        $timer->stop('evidence');

        $timer->start('draft');
        $clock->advance(120);
        $timer->stop('draft');

        $timer->start('verify');
        $clock->advance(15);
        $timer->stop('verify');

        self::assertSame(
            ['evidence' => 40, 'draft' => 120, 'verify' => 15],
            $timer->timings(),
        );
    }

    public function testRepeatedStartAndStopOnSameStageAccumulates(): void
    {
        $clock = new StageTimerFakeClock(0);
        $timer = new StageTimer($clock);

        $timer->start('evidence:problems');
        $clock->advance(20);
        $timer->stop('evidence:problems');

        $timer->start('evidence:problems');
        $clock->advance(35);
        $timer->stop('evidence:problems');

        self::assertSame(['evidence:problems' => 55], $timer->timings());
    }

    public function testNegativeElapsedFromClockAnomalyIsClampedToZero(): void
    {
        $clock = new StageTimerFakeClock(1000);
        $timer = new StageTimer($clock);

        $timer->start('draft');
        $clock->advance(-50);
        $timer->stop('draft');

        self::assertSame(['draft' => 0], $timer->timings());
    }

    public function testStopClearsStartSoSubsequentStopIsNoOp(): void
    {
        $clock = new StageTimerFakeClock(0);
        $timer = new StageTimer($clock);

        $timer->start('evidence');
        $clock->advance(10);
        $timer->stop('evidence');

        $clock->advance(100);
        $timer->stop('evidence');

        self::assertSame(['evidence' => 10], $timer->timings());
    }
}

final class StageTimerFakeClock implements AgentForgeClock
{
    public function __construct(private int $nowMs = 0)
    {
    }

    public function advance(int $ms): void
    {
        $this->nowMs += $ms;
    }

    public function nowMs(): int
    {
        return $this->nowMs;
    }
}
