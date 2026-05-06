<?php

/**
 * Test fake PSR-20 wall clock that always returns the same instant.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Tests\Isolated\AgentForge\Support;

use DateTimeImmutable;
use Psr\Clock\ClockInterface;

final readonly class FrozenWallClock implements ClockInterface
{
    public function __construct(private DateTimeImmutable $instant)
    {
    }

    public function now(): DateTimeImmutable
    {
        return $this->instant;
    }
}
