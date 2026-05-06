<?php

/**
 * Wall-clock implementation of Psr\Clock\ClockInterface for AgentForge.
 *
 * This is the only class outside OpenEMR\AgentForge\Time\* permitted to call
 * `new \DateTimeImmutable()` (zero-arg / 'now') in AgentForge code; all other
 * callers should depend on Psr\Clock\ClockInterface and inject it. Hydration
 * parsers that read a date string out of a database row (e.g. `new
 * \DateTimeImmutable($row['updated_at'])`) remain allowed.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\AgentForge\Time;

use DateTimeImmutable;
use DateTimeZone;
use Psr\Clock\ClockInterface;

final class SystemPsrClock implements ClockInterface
{
    public function __construct(private readonly ?DateTimeZone $timezone = null)
    {
    }

    public function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('now', $this->timezone);
    }
}
