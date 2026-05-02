<?php

/**
 * Isolated tests for AgentForge Deadline.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Tests\Isolated\AgentForge;

use OpenEMR\AgentForge\AgentForgeClock;
use OpenEMR\AgentForge\Deadline;
use PHPUnit\Framework\TestCase;

final class DeadlineTest extends TestCase
{
    public function testRemainingMsReturnsBudgetWhenNoTimeHasPassed(): void
    {
        $clock = new DeadlineFakeClock(1000);
        $deadline = new Deadline($clock, 8000);

        self::assertSame(8000, $deadline->remainingMs());
        self::assertFalse($deadline->exceeded());
    }

    public function testRemainingMsDecreasesAsTimeAdvances(): void
    {
        $clock = new DeadlineFakeClock(1000);
        $deadline = new Deadline($clock, 8000);

        $clock->advance(3000);

        self::assertSame(5000, $deadline->remainingMs());
        self::assertFalse($deadline->exceeded());
    }

    public function testExceededIsFalseWhenElapsedEqualsBudget(): void
    {
        $clock = new DeadlineFakeClock(1000);
        $deadline = new Deadline($clock, 500);

        $clock->advance(500);

        self::assertSame(0, $deadline->remainingMs());
        self::assertFalse($deadline->exceeded());
    }

    public function testExceededIsTrueWhenElapsedSurpassesBudget(): void
    {
        $clock = new DeadlineFakeClock(1000);
        $deadline = new Deadline($clock, 500);

        $clock->advance(501);

        self::assertSame(-1, $deadline->remainingMs());
        self::assertTrue($deadline->exceeded());
    }

    public function testNegativeBudgetActsAsUnboundedDeadline(): void
    {
        $clock = new DeadlineFakeClock(0);
        $deadline = new Deadline($clock, -1);

        $clock->advance(1_000_000);

        self::assertSame(PHP_INT_MAX, $deadline->remainingMs());
        self::assertFalse($deadline->exceeded());
        self::assertSame((float) PHP_INT_MAX, $deadline->remainingSeconds());
    }

    public function testRemainingSecondsConvertsMillisecondsToSeconds(): void
    {
        $clock = new DeadlineFakeClock(1000);
        $deadline = new Deadline($clock, 8000);

        $clock->advance(3000);

        self::assertSame(5.0, $deadline->remainingSeconds());
    }

    public function testRemainingSecondsClampsToMinimumWhenBudgetExhausted(): void
    {
        $clock = new DeadlineFakeClock(1000);
        $deadline = new Deadline($clock, 500);

        $clock->advance(2000);

        self::assertSame(0.001, $deadline->remainingSeconds());
        self::assertSame(0.5, $deadline->remainingSeconds(0.5));
    }

    public function testStartTimeIsCapturedAtConstructionNotFirstCall(): void
    {
        $clock = new DeadlineFakeClock(1000);
        $deadline = new Deadline($clock, 8000);

        $clock->advance(2000);

        self::assertSame(6000, $deadline->remainingMs());
    }
}

final class DeadlineFakeClock implements AgentForgeClock
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
