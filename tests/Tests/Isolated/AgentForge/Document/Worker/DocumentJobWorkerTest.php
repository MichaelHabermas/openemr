<?php

/**
 * Isolated tests for the AgentForge document worker loop.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Tests\Isolated\AgentForge\Document\Worker;

use DateTimeImmutable;
use OpenEMR\AgentForge\Auth\PatientId;
use OpenEMR\AgentForge\Document\DocumentId;
use OpenEMR\AgentForge\Document\DocumentJob;
use OpenEMR\AgentForge\Document\DocumentJobId;
use OpenEMR\AgentForge\Document\DocumentType;
use OpenEMR\AgentForge\Document\JobStatus;
use OpenEMR\AgentForge\Document\Worker\DocumentJobProcessor;
use OpenEMR\AgentForge\Document\Worker\DocumentJobWorker;
use OpenEMR\AgentForge\Document\Worker\DocumentJobWorkerRepository;
use OpenEMR\AgentForge\Document\Worker\DocumentLoader;
use OpenEMR\AgentForge\Document\Worker\DocumentLoadException;
use OpenEMR\AgentForge\Document\Worker\DocumentLoadResult;
use OpenEMR\AgentForge\Document\Worker\JobClaimer;
use OpenEMR\AgentForge\Document\Worker\LockToken;
use OpenEMR\AgentForge\Document\Worker\NoopDocumentJobProcessor;
use OpenEMR\AgentForge\Document\Worker\ProcessingResult;
use OpenEMR\AgentForge\Document\Worker\WorkerHeartbeat;
use OpenEMR\AgentForge\Document\Worker\WorkerHeartbeatRepository;
use OpenEMR\AgentForge\Document\Worker\WorkerName;
use OpenEMR\AgentForge\Observability\PatientRefHasher;
use OpenEMR\AgentForge\Observability\SensitiveLogPolicy;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use RuntimeException;
use Stringable;
use Throwable;
use TypeError;

final class DocumentJobWorkerTest extends TestCase
{
    public function testLoopLifecycleProcessesClaimedJobAndStops(): void
    {
        $job = self::job(1);
        $repository = new WorkerDocumentJobRepository($job);
        $claimer = new WorkerJobClaimer([$job]);
        $heartbeats = new WorkerHeartbeatStore();
        $logger = new WorkerRecordingLogger();

        $exitCode = $this->worker(
            claimer: $claimer,
            repository: $repository,
            loader: new StaticDocumentLoader(new DocumentLoadResult('document-bytes', 'application/pdf', 'lab.pdf')),
            processor: new StaticDocumentJobProcessor(ProcessingResult::succeeded()),
            heartbeats: $heartbeats,
            logger: $logger,
        )->run(1, 0);

        $this->assertSame(0, $exitCode);
        $this->assertCount(1, $repository->finished);
        $this->assertSame(JobStatus::Succeeded, $repository->finished[0]['status']);
        $this->assertSame(['starting', 'running', 'stopping', 'stopped'], $heartbeats->statuses());
        $this->assertSame(
            'clinical_document.worker.job_completed',
            $this->recordByMessage($logger, 'clinical_document.worker.job_completed')['message'],
        );
    }

    public function testIdleIterationDoesNotProcessJob(): void
    {
        $repository = new WorkerDocumentJobRepository(null);
        $heartbeats = new WorkerHeartbeatStore();
        $logger = new WorkerRecordingLogger();
        $sleeps = [];

        $exitCode = $this->worker(
            claimer: new WorkerJobClaimer([]),
            repository: $repository,
            loader: new StaticDocumentLoader(new DocumentLoadResult('document-bytes', 'text/plain', 'note.txt')),
            processor: new StaticDocumentJobProcessor(ProcessingResult::succeeded()),
            heartbeats: $heartbeats,
            logger: $logger,
            sleep: static function (int $seconds) use (&$sleeps): void {
                $sleeps[] = $seconds;
            },
        )->run(1, 2);

        $this->assertSame(0, $exitCode);
        $this->assertSame([], $repository->finished);
        $this->assertSame([1, 1], $sleeps);
        $this->assertContains('idle', $heartbeats->statuses());
        $this->assertSame(
            'clinical_document.worker.idle',
            $this->recordByMessage($logger, 'clinical_document.worker.idle')['message'],
        );
    }

    public function testBoundedIterationsStopBeforeQueueIsDrained(): void
    {
        $jobs = [self::job(1), self::job(2), self::job(3)];
        $claimer = new WorkerJobClaimer($jobs);
        $repository = new WorkerDocumentJobRepository(null);

        $this->worker(
            claimer: $claimer,
            repository: $repository,
            loader: new StaticDocumentLoader(new DocumentLoadResult('document-bytes', 'application/pdf', 'lab.pdf')),
            processor: new StaticDocumentJobProcessor(ProcessingResult::succeeded()),
        )->run(2, 0);

        $this->assertSame(2, $claimer->claims);
        $this->assertCount(2, $repository->finished);
        $this->assertCount(1, $claimer->remainingJobs);
    }

    public function testLoaderFailureMarksJobFailedWithoutLoggingDocumentPayload(): void
    {
        $job = self::job(10);
        $repository = new WorkerDocumentJobRepository($job);
        $logger = new WorkerRecordingLogger();

        $this->worker(
            claimer: new WorkerJobClaimer([$job]),
            repository: $repository,
            loader: new ThrowingDocumentLoader(DocumentLoadException::missing()),
            processor: new StaticDocumentJobProcessor(ProcessingResult::succeeded()),
            logger: $logger,
        )->run(1, 0);

        $this->assertSame(JobStatus::Failed, $repository->finished[0]['status']);
        $this->assertSame('source_document_missing', $repository->finished[0]['errorCode']);
        $failure = $this->recordByMessage($logger, 'clinical_document.worker.job_failed');
        $this->assertSame('source_document_missing', $failure['context']['error_code']);
        $this->assertArrayNotHasKey('document_text', $failure['context']);
        $this->assertArrayNotHasKey('exception', $failure['context']);
    }

    public function testNoopProcessorFailureMarksJobFailed(): void
    {
        $job = self::job(11);
        $repository = new WorkerDocumentJobRepository($job);

        $this->worker(
            claimer: new WorkerJobClaimer([$job]),
            repository: $repository,
            loader: new StaticDocumentLoader(new DocumentLoadResult('document-bytes', 'application/pdf', 'lab.pdf')),
            processor: new NoopDocumentJobProcessor(),
        )->run(1, 0);

        $this->assertSame(JobStatus::Failed, $repository->finished[0]['status']);
        $this->assertSame('extraction_not_implemented', $repository->finished[0]['errorCode']);
    }

    public function testProcessorRuntimeExceptionMarksJobFailedWithoutLoggingExceptionMessage(): void
    {
        $job = self::job(13);
        $repository = new WorkerDocumentJobRepository($job);
        $logger = new WorkerRecordingLogger();

        $this->worker(
            claimer: new WorkerJobClaimer([$job]),
            repository: $repository,
            loader: new StaticDocumentLoader(new DocumentLoadResult('document-bytes', 'application/pdf', 'lab.pdf')),
            processor: new ThrowingDocumentJobProcessor(new RuntimeException('contains sensitive legacy details')),
            logger: $logger,
        )->run(1, 0);

        $this->assertSame(JobStatus::Failed, $repository->finished[0]['status']);
        $this->assertSame('processor_failed', $repository->finished[0]['errorCode']);
        $failure = $this->recordByMessage($logger, 'clinical_document.worker.job_failed');
        $this->assertSame('processor_failed', $failure['context']['error_code']);
        $this->assertArrayNotHasKey('error_message', $failure['context']);
    }

    public function testUnexpectedProcessorThrowableFinalizesJobAndStopsHeartbeatBeforeRethrow(): void
    {
        $job = self::job(15);
        $repository = new WorkerDocumentJobRepository($job);
        $heartbeats = new WorkerHeartbeatStore();
        $logger = new WorkerRecordingLogger();

        try {
            $this->worker(
                claimer: new WorkerJobClaimer([$job]),
                repository: $repository,
                loader: new StaticDocumentLoader(new DocumentLoadResult('document-bytes', 'application/pdf', 'lab.pdf')),
                processor: new ThrowingDocumentJobProcessor(new TypeError('contains sensitive legacy details')),
                heartbeats: $heartbeats,
                logger: $logger,
            )->run(1, 0);
            $this->fail('Expected processor throwable to propagate after cleanup.');
        } catch (TypeError) {
            $this->assertSame(JobStatus::Failed, $repository->finished[0]['status']);
            $this->assertSame('processor_failed', $repository->finished[0]['errorCode']);
            $statuses = $heartbeats->statuses();
            $this->assertSame('stopped', $statuses[array_key_last($statuses)]);
            $failure = $this->recordByMessage($logger, 'clinical_document.worker.job_failed');
            $this->assertSame('processor_failed', $failure['context']['error_code']);
            $this->assertArrayNotHasKey('error_message', $failure['context']);
        }
    }

    public function testLostClaimFinishDoesNotCountOrLogCompletion(): void
    {
        $job = self::job(14);
        $repository = new WorkerDocumentJobRepository($job);
        $repository->finishAffectedRows = 0;
        $logger = new WorkerRecordingLogger();

        $this->worker(
            claimer: new WorkerJobClaimer([$job]),
            repository: $repository,
            loader: new StaticDocumentLoader(new DocumentLoadResult('document-bytes', 'application/pdf', 'lab.pdf')),
            processor: new StaticDocumentJobProcessor(ProcessingResult::succeeded()),
            logger: $logger,
        )->run(1, 0);

        $this->assertCount(1, $repository->finished);
        $this->assertNull($this->maybeRecordByMessage($logger, 'clinical_document.worker.job_completed'));
        $shutdown = $this->recordByMessage($logger, 'clinical_document.worker.shutdown');
        $this->assertSame(0, $shutdown['context']['jobs_processed']);
    }

    public function testLoggedContextUsesOnlySensitiveLogPolicyAllowedKeys(): void
    {
        $logger = new WorkerRecordingLogger();

        $this->worker(
            claimer: new WorkerJobClaimer([self::job(12)]),
            repository: new WorkerDocumentJobRepository(null),
            loader: new StaticDocumentLoader(new DocumentLoadResult('document-bytes', 'application/pdf', 'lab.pdf')),
            processor: new NoopDocumentJobProcessor(),
            logger: $logger,
        )->run(1, 0);

        foreach ($logger->records as $record) {
            $this->assertSame($record['context'], SensitiveLogPolicy::sanitizeContext($record['context']));
            $this->assertFalse(SensitiveLogPolicy::containsForbiddenKey($record['context']));
        }

        $failedContext = $this->recordByMessage($logger, 'clinical_document.worker.job_failed')['context'];
        $this->assertArrayHasKey('patient_ref', $failedContext);
        $this->assertArrayNotHasKey('patient_id', $failedContext);
        $this->assertArrayNotHasKey('error_message', $failedContext);
    }

    /**
     * @return array{level: mixed, message: string|Stringable, context: array<string, mixed>}
     */
    private function recordByMessage(WorkerRecordingLogger $logger, string $message): array
    {
        $record = $this->maybeRecordByMessage($logger, $message);
        if ($record !== null) {
            return $record;
        }

        $this->fail("Missing log record: {$message}");
    }

    /**
     * @return array{level: mixed, message: string|Stringable, context: array<string, mixed>}|null
     */
    private function maybeRecordByMessage(WorkerRecordingLogger $logger, string $message): ?array
    {
        foreach ($logger->records as $record) {
            if ($record['message'] === $message) {
                return $record;
            }
        }

        return null;
    }

    private function worker(
        JobClaimer $claimer,
        DocumentJobWorkerRepository $repository,
        DocumentLoader $loader,
        DocumentJobProcessor $processor,
        ?WorkerHeartbeatStore $heartbeats = null,
        ?WorkerRecordingLogger $logger = null,
        ?callable $sleep = null,
    ): DocumentJobWorker {
        return new DocumentJobWorker(
            WorkerName::IntakeExtractor,
            $claimer,
            $repository,
            $loader,
            $processor,
            $heartbeats ?? new WorkerHeartbeatStore(),
            $logger ?? new WorkerRecordingLogger(),
            new PatientRefHasher('test-salt'),
            12345,
            $sleep,
        );
    }

    private static function job(int $id): DocumentJob
    {
        return new DocumentJob(
            id: new DocumentJobId($id),
            patientId: new PatientId(900001),
            documentId: new DocumentId(123 + $id),
            docType: DocumentType::LabPdf,
            status: JobStatus::Running,
            attempts: 1,
            lockToken: null,
            createdAt: new DateTimeImmutable('2026-05-05T00:00:00+00:00'),
            startedAt: null,
            finishedAt: null,
            errorCode: null,
            errorMessage: null,
            retractedAt: null,
            retractionReason: null,
        );
    }
}

final class WorkerJobClaimer implements JobClaimer
{
    /** @var list<DocumentJob> */
    public array $remainingJobs;
    public int $claims = 0;

    /** @param list<DocumentJob> $jobs */
    public function __construct(array $jobs)
    {
        $this->remainingJobs = $jobs;
    }

    public function claimNext(WorkerName $workerName, LockToken $lockToken): ?DocumentJob
    {
        $this->claims++;

        return array_shift($this->remainingJobs);
    }
}

final class WorkerDocumentJobRepository implements DocumentJobWorkerRepository
{
    /** @var list<array{id: DocumentJobId, status: JobStatus, errorCode: ?string, errorMessage: ?string}> */
    public array $finished = [];
    public int $finishAffectedRows = 1;

    public function __construct(private readonly ?DocumentJob $claimedJob)
    {
    }

    public function markFinished(
        DocumentJobId $id,
        LockToken $lockToken,
        JobStatus $terminal,
        ?string $errorCode,
        ?string $errorMessage,
    ): int {
        $this->finished[] = [
            'id' => $id,
            'status' => $terminal,
            'errorCode' => $errorCode,
            'errorMessage' => $errorMessage,
        ];

        return $this->finishAffectedRows;
    }

    public function findClaimedByLockToken(LockToken $lockToken): ?DocumentJob
    {
        return $this->claimedJob;
    }
}

final readonly class StaticDocumentLoader implements DocumentLoader
{
    public function __construct(private DocumentLoadResult $result)
    {
    }

    public function load(DocumentId $documentId): DocumentLoadResult
    {
        return $this->result;
    }
}

final readonly class ThrowingDocumentLoader implements DocumentLoader
{
    public function __construct(private DocumentLoadException $exception)
    {
    }

    public function load(DocumentId $documentId): DocumentLoadResult
    {
        throw $this->exception;
    }
}

final class StaticDocumentJobProcessor implements DocumentJobProcessor
{
    public int $processed = 0;

    public function __construct(private readonly ProcessingResult $result)
    {
    }

    public function process(DocumentJob $job, DocumentLoadResult $document): ProcessingResult
    {
        $this->processed++;

        return $this->result;
    }
}

final readonly class ThrowingDocumentJobProcessor implements DocumentJobProcessor
{
    public function __construct(private Throwable $exception)
    {
    }

    public function process(DocumentJob $job, DocumentLoadResult $document): ProcessingResult
    {
        throw $this->exception;
    }
}

final class WorkerHeartbeatStore implements WorkerHeartbeatRepository
{
    /** @var list<WorkerHeartbeat> */
    public array $heartbeats = [];

    public function upsert(WorkerHeartbeat $heartbeat): void
    {
        $this->heartbeats[] = $heartbeat;
    }

    public function findByWorker(WorkerName $workerName): ?WorkerHeartbeat
    {
        foreach (array_reverse($this->heartbeats) as $heartbeat) {
            if ($heartbeat->workerName === $workerName) {
                return $heartbeat;
            }
        }

        return null;
    }

    /** @return list<string> */
    public function statuses(): array
    {
        return array_map(
            static fn(WorkerHeartbeat $heartbeat): string => $heartbeat->status->value,
            $this->heartbeats,
        );
    }
}

final class WorkerRecordingLogger extends AbstractLogger
{
    /** @var list<array{level: mixed, message: string|Stringable, context: array<string, mixed>}> */
    public array $records = [];

    /** @param array<mixed> $context */
    public function log($level, string|Stringable $message, array $context = []): void
    {
        $this->records[] = [
            'level' => $level,
            'message' => $message,
            'context' => $this->stringKeyedContext($context),
        ];
    }

    /**
     * @param array<mixed> $context
     * @return array<string, mixed>
     */
    private function stringKeyedContext(array $context): array
    {
        $normalized = [];
        foreach ($context as $key => $value) {
            if (is_string($key)) {
                $normalized[$key] = $value;
            }
        }

        return $normalized;
    }
}
