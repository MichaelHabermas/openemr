<?php

/**
 * Parsed CLI options for the document worker.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\AgentForge\Document\Worker;

use InvalidArgumentException;
use OpenEMR\AgentForge\AgentForgeEnv;

final readonly class WorkerArgs
{
    public function __construct(
        public WorkerName $workerName,
        public int $maxIterations,
        public int $idleSleepSeconds,
    ) {
        if ($maxIterations < 0) {
            throw new InvalidArgumentException('Max iterations must be zero or greater.');
        }
        if ($idleSleepSeconds < 0) {
            throw new InvalidArgumentException('Idle sleep seconds must be zero or greater.');
        }
    }

    /** @param list<string> $argv */
    public static function fromArgv(array $argv): self
    {
        $worker = AgentForgeEnv::string('AGENTFORGE_WORKER_NAME');
        $maxIterations = AgentForgeEnv::int('AGENTFORGE_WORKER_MAX_ITERATIONS') ?? 0;
        $idleSleepSeconds = AgentForgeEnv::int('AGENTFORGE_WORKER_IDLE_SLEEP_SECONDS') ?? 5;

        foreach (array_slice($argv, 1) as $arg) {
            if (str_starts_with($arg, '--worker=')) {
                $worker = substr($arg, strlen('--worker='));
                continue;
            }
            if (str_starts_with($arg, '--max-iterations=')) {
                $maxIterations = self::parseInt(substr($arg, strlen('--max-iterations=')), 'max iterations');
                continue;
            }
            if ($arg === '--one-shot') {
                $maxIterations = 1;
                continue;
            }
            if (str_starts_with($arg, '--idle-sleep-seconds=')) {
                $idleSleepSeconds = self::parseInt(substr($arg, strlen('--idle-sleep-seconds=')), 'idle sleep seconds');
                continue;
            }

            throw new InvalidArgumentException("Unknown worker flag: {$arg}");
        }

        if ($worker === null) {
            throw new InvalidArgumentException('Missing required --worker=NAME flag.');
        }

        return new self(WorkerName::fromStringOrThrow($worker), $maxIterations, $idleSleepSeconds);
    }

    private static function parseInt(string $raw, string $label): int
    {
        if (!preg_match('/\A\d+\z/', $raw)) {
            throw new InvalidArgumentException("Invalid {$label}: {$raw}");
        }

        return (int) $raw;
    }
}
