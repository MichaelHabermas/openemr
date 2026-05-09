<?php

/**
 * Long-running AgentForge document job worker loop.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\AgentForge\Document\Worker;

use DateTimeImmutable;
use OpenEMR\AgentForge\Document\DocumentJob;
use OpenEMR\AgentForge\Document\JobStatus;
use OpenEMR\AgentForge\Observability\PatientRefHasher;
use OpenEMR\AgentForge\Observability\SensitiveLogPolicy;
use OpenEMR\AgentForge\Observability\TraceId;
use OpenEMR\AgentForge\Time\MonotonicClock;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;

final class DocumentJobWorker
{
    private int $iterationCount = 0;
    private int $jobsProcessed = 0;
    private int $jobsFailed = 0;
    private bool $shouldStop = false;
    private DateTimeImmutable $startedAt;

    /** @var callable(int): void */
    private $sleep;

    /** @param callable(int): void|null $sleep */
    public function __construct(
        private readonly WorkerName $workerName,
        private readonly JobClaimer $claimer,
        private readonly DocumentJobWorkerRepository $jobs,
        private readonly DocumentLoader $loader,
        private readonly DocumentJobProcessor $processor,
        private readonly WorkerHeartbeatRepository $heartbeats,
        private readonly LoggerInterface $logger,
        private readonly PatientRefHasher $patientRefHasher,
        private readonly MonotonicClock $clock,
        private readonly ClockInterface $wallClock,
        private readonly int $processId,
        ?callable $sleep = null,
    ) {
        $this->startedAt = $this->wallClock->now();
        $this->sleep = $sleep ?? static function (int $seconds): void {
            sleep($seconds);
        };
    }

    public function requestStop(): void
    {
        $this->shouldStop = true;
    }

    public function run(int $maxIterations, int $idleSleepSeconds): int
    {
        $this->heartbeat(WorkerStatus::Starting);
        $this->log('info', 'clinical_document.worker.started', [
            'worker_status' => WorkerStatus::Starting->value,
        ]);

        while (!$this->shouldStop && ($maxIterations === 0 || $this->iterationCount < $maxIterations)) {
            $this->heartbeat(WorkerStatus::Running);
            $lockToken = LockToken::generate();
            $job = $this->claimer->claimNext($this->workerName, $lockToken);

            if ($job === null) {
                $this->log('info', 'clinical_document.worker.idle', [
                    'worker_status' => WorkerStatus::Idle->value,
                    'idle_seconds' => $idleSleepSeconds,
                    'claimed_count' => 0,
                ]);
                $this->heartbeat(WorkerStatus::Idle);
                ++$this->iterationCount;
                $this->sleepWhileIdle($idleSleepSeconds);
                $this->dispatchSignals();
                continue;
            }

            $this->processClaimedJob($job, $lockToken);
            ++$this->iterationCount;
            $this->dispatchSignals();
        }

        $this->heartbeat(WorkerStatus::Stopping);
        $this->heartbeat(WorkerStatus::Stopped);
        $this->log('info', 'clinical_document.worker.shutdown', [
            'worker_status' => WorkerStatus::Stopped->value,
        ]);

        return 0;
    }

    private function processClaimedJob(DocumentJob $job, LockToken $lockToken): void
    {
        if ($job->status !== JobStatus::Running || $job->retractedAt !== null || $job->id === null) {
            return;
        }

        $traceId = TraceId::generate();
        $startedMs = $this->clock->nowMs();

        try {
            $document = $this->loader->load($job->documentId);
            $result = $this->processor->process($job, $document);
        } catch (DocumentLoadException $e) {
            $result = ProcessingResult::failed($e->errorCode, 'Source document could not be loaded for processing.');
        } catch (RuntimeException $e) {
            $result = ProcessingResult::failed('processor_failed', 'Document processor failed unexpectedly.');
        } catch (Throwable $e) {
            $result = ProcessingResult::failed('processor_failed', 'Document processor failed unexpectedly.');
            $this->finishClaimedJob($job, $lockToken, $result, $startedMs, $traceId);
            $this->heartbeat(WorkerStatus::Stopped);
            throw $e;
        }

        $this->finishClaimedJob($job, $lockToken, $result, $startedMs, $traceId);
    }

    private function finishClaimedJob(
        DocumentJob $job,
        LockToken $lockToken,
        ProcessingResult $result,
        int $startedMs,
        ?TraceId $traceId = null,
    ): void {
        if ($job->id === null) {
            return;
        }

        $finished = $this->jobs->markFinished(
            $job->id,
            $lockToken,
            $result->terminalStatus,
            $result->errorCode,
            $result->errorMessage,
        );
        if ($finished === 0) {
            return;
        }
        ++$this->jobsProcessed;

        if ($result->failedJob()) {
            ++$this->jobsFailed;
            $this->log('warning', 'clinical_document.worker.job_failed', $this->jobContext($job, [
                'status' => JobStatus::Failed->value,
                'error_code' => $result->errorCode,
                'latency_ms' => $this->latencyMs($startedMs),
            ], $traceId));
            return;
        }

        $this->log('info', 'clinical_document.worker.job_completed', $this->jobContext($job, [
            'status' => JobStatus::Succeeded->value,
            'latency_ms' => $this->latencyMs($startedMs),
        ], $traceId));
    }

    private function heartbeat(WorkerStatus $status): void
    {
        $now = $this->wallClock->now();
        $heartbeat = new WorkerHeartbeat(
            workerName: $this->workerName,
            processId: $this->processId,
            status: $status,
            iterationCount: $this->iterationCount,
            jobsProcessed: $this->jobsProcessed,
            jobsFailed: $this->jobsFailed,
            startedAt: $this->startedAt,
            lastHeartbeatAt: $now,
            stoppedAt: $status === WorkerStatus::Stopped ? $now : null,
        );

        $this->heartbeats->upsert($heartbeat);
        $this->log('info', 'clinical_document.worker.heartbeat', [
            'worker_status' => $status->value,
        ]);
    }

    /**
     * @param array<string, mixed> $extra
     * @return array<string, mixed>
     */
    private function jobContext(DocumentJob $job, array $extra = [], ?TraceId $traceId = null): array
    {
        $context = [
            'job_id' => $job->id?->value,
            'patient_ref' => $this->patientRefHasher->hash($job->patientId),
            'document_id' => $job->documentId->value,
            'doc_type' => $job->docType->value,
            'status' => $job->status->value,
            'attempts' => $job->attempts,
            'trace_id' => $traceId?->value,
        ];
        foreach ($extra as $key => $value) {
            $context[$key] = $value;
        }

        return $context;
    }

    /** @param array<string, mixed> $context */
    private function log(string $level, string $event, array $context): void
    {
        $base = [
            'worker' => $this->workerName->value,
            'process_id' => $this->processId,
            'iteration_count' => $this->iterationCount,
            'jobs_processed' => $this->jobsProcessed,
            'jobs_failed' => $this->jobsFailed,
        ];
        $this->logger->log($level, $event, SensitiveLogPolicy::sanitizeContext(array_merge($base, $context)));
    }

    private function latencyMs(int $startedMs): int
    {
        return max(0, $this->clock->nowMs() - $startedMs);
    }

    private function dispatchSignals(): void
    {
        if (function_exists('pcntl_signal_dispatch')) {
            pcntl_signal_dispatch();
        }
    }

    private function sleepWhileIdle(int $idleSleepSeconds): void
    {
        $remaining = $idleSleepSeconds;
        while ($remaining > 0 && !$this->shouldStop) {
            $this->dispatchSignals();
            if ($this->shouldStop) {
                return;
            }
            ($this->sleep)(1);
            --$remaining;
            $this->dispatchSignals();
        }
    }
}
