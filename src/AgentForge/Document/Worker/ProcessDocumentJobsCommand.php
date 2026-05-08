<?php

/**
 * CLI command for the document worker.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\AgentForge\Document\Worker;

use DomainException;
use InvalidArgumentException;
use OpenEMR\AgentForge\Observability\SensitiveLogPolicy;
use OpenEMR\BC\ServiceContainer;
use RuntimeException;

final class ProcessDocumentJobsCommand
{
    /** @param list<string> $argv */
    public static function main(array $argv): int
    {
        if (in_array('--help', $argv, true) || in_array('-h', $argv, true)) {
            echo self::usage();
            return 0;
        }

        $logger = ServiceContainer::getLogger();

        try {
            if (in_array('--mark-stopped', $argv, true)) {
                $workerName = self::workerNameFromArgv($argv);
                DocumentJobWorkerFactory::markStopped($workerName, self::processIdFromArgv($argv));
                $logger->info('clinical_document.worker.shutdown', SensitiveLogPolicy::sanitizeContext([
                    'worker' => $workerName->value,
                    'process_id' => self::processIdFromArgv($argv),
                    'worker_status' => WorkerStatus::Stopped->value,
                ]));

                return 0;
            }

            $args = WorkerArgs::fromArgv($argv);
            $worker = DocumentJobWorkerFactory::createDefault($args->workerName);

            if (function_exists('pcntl_signal')) {
                if (function_exists('pcntl_async_signals')) {
                    pcntl_async_signals(true);
                }
                pcntl_signal(SIGTERM, static fn() => $worker->requestStop(), false);
                pcntl_signal(SIGINT, static fn() => $worker->requestStop(), false);
            }

            return $worker->run($args->maxIterations, $args->idleSleepSeconds);
        } catch (InvalidArgumentException | DomainException | RuntimeException $e) {
            $logger->error('clinical_document.worker.fatal', SensitiveLogPolicy::throwableErrorContext($e));

            return 1;
        }
    }

    public static function usage(): string
    {
        return <<<USAGE
Usage: php agent-forge/scripts/process-document-jobs.php --worker=NAME [options]

Options:
  --worker=NAME                 Document job worker role. Only intake-extractor claims extraction jobs.
  --one-shot                    Run one claim/idle iteration and exit.
  --max-iterations=N            Stop after N loop iterations. 0 means unlimited.
  --idle-sleep-seconds=N        Seconds to wait between idle polls.
  --mark-stopped                Mark the worker heartbeat stopped and exit.
  --process-id=N                Process id to use with --mark-stopped.
  -h, --help                    Show this help.

Environment:
  AGENTFORGE_WORKER_NAME
  AGENTFORGE_WORKER_MAX_ITERATIONS
  AGENTFORGE_WORKER_IDLE_SLEEP_SECONDS

Inspectable AgentForge graph nodes:
  supervisor and evidence-retriever are used in request-time routing and handoff logs;
  they do not consume the clinical_document_processing_jobs extraction queue.

USAGE;
    }

    /** @param list<string> $argv */
    private static function workerNameFromArgv(array $argv): WorkerName
    {
        foreach (array_slice($argv, 1) as $arg) {
            if (str_starts_with($arg, '--worker=')) {
                return WorkerName::fromStringOrThrow(substr($arg, strlen('--worker=')));
            }
        }

        $worker = getenv('AGENTFORGE_WORKER_NAME');
        if (!is_string($worker) || $worker === '') {
            throw new InvalidArgumentException('Missing required --worker=NAME flag.');
        }

        return WorkerName::fromStringOrThrow($worker);
    }

    /** @param list<string> $argv */
    private static function processIdFromArgv(array $argv): int
    {
        foreach (array_slice($argv, 1) as $arg) {
            if (str_starts_with($arg, '--process-id=')) {
                return self::positiveInt(substr($arg, strlen('--process-id=')), 'process id');
            }
        }

        return getmypid() ?: 1;
    }

    private static function positiveInt(string $raw, string $label): int
    {
        if (!preg_match('/\A[1-9]\d*\z/', $raw)) {
            throw new InvalidArgumentException("Invalid {$label}: {$raw}");
        }

        return (int) $raw;
    }
}
