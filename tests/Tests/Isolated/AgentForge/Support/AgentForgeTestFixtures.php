<?php

/**
 * Static factories for AgentForge isolated-test fixtures.
 *
 * Centralizes the previously hand-rolled clock fakes so tests can drop a
 * one-line factory call instead of re-declaring an anonymous fake.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Tests\Isolated\AgentForge\Support;

use DateTimeImmutable;

final class AgentForgeTestFixtures
{
    public static function frozenMonotonicClock(int $nowMs = 1_000): FrozenMonotonicClock
    {
        return new FrozenMonotonicClock($nowMs);
    }

    public static function advanceableMonotonicClock(int $nowMs = 0): AdvanceableMonotonicClock
    {
        return new AdvanceableMonotonicClock($nowMs);
    }

    /** @param list<int> $ticks */
    public static function tickingMonotonicClock(array $ticks): TickingMonotonicClock
    {
        return new TickingMonotonicClock($ticks);
    }

    public static function frozenWallClock(string $atomTimestamp = '2026-05-02T12:00:00+00:00'): FrozenWallClock
    {
        return new FrozenWallClock(new DateTimeImmutable($atomTimestamp));
    }
}
