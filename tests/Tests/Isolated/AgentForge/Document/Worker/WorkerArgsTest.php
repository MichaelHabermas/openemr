<?php

/**
 * Isolated tests for AgentForge document worker CLI parsing.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Tests\Isolated\AgentForge\Document\Worker;

use InvalidArgumentException;
use OpenEMR\AgentForge\Document\Worker\WorkerArgs;
use OpenEMR\AgentForge\Document\Worker\WorkerName;
use PHPUnit\Framework\TestCase;

final class WorkerArgsTest extends TestCase
{
    public function testParsesExplicitWorkerOptions(): void
    {
        $args = WorkerArgs::fromArgv([
            'process-document-jobs.php',
            '--worker=intake-extractor',
            '--max-iterations=3',
            '--idle-sleep-seconds=2',
        ]);

        $this->assertSame(WorkerName::IntakeExtractor, $args->workerName);
        $this->assertSame(3, $args->maxIterations);
        $this->assertSame(2, $args->idleSleepSeconds);
    }

    public function testOneShotMapsToOneIteration(): void
    {
        $args = WorkerArgs::fromArgv(['process-document-jobs.php', '--worker=intake-extractor', '--one-shot']);

        $this->assertSame(1, $args->maxIterations);
    }

    public function testRejectsUnknownOptions(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Unknown worker flag: --surprise');

        WorkerArgs::fromArgv(['process-document-jobs.php', '--worker=intake-extractor', '--surprise']);
    }

    public function testRequiresWorker(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Missing required --worker=NAME flag.');

        WorkerArgs::fromArgv(['process-document-jobs.php']);
    }
}
