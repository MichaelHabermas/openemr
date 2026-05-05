<?php

/**
 * Isolated tests for AgentForge document job status enum.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Tests\Isolated\AgentForge\Document;

use DomainException;
use OpenEMR\AgentForge\Document\JobStatus;
use PHPUnit\Framework\TestCase;

final class JobStatusTest extends TestCase
{
    public function testStatusesAreExactlyWeekTwoLifecycleValues(): void
    {
        $this->assertSame(
            ['pending', 'running', 'succeeded', 'failed', 'retracted'],
            array_map(static fn (JobStatus $status): string => $status->value, JobStatus::cases()),
        );
    }

    public function testFromStringOrThrowRejectsUnsupportedStatus(): void
    {
        $this->expectException(DomainException::class);

        JobStatus::fromStringOrThrow('queued');
    }
}
