<?php

/**
 * Isolated tests for AgentForge document upload enqueue behavior.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Tests\Isolated\AgentForge\Document;

use DateTimeImmutable;
use InvalidArgumentException;
use OpenEMR\AgentForge\Auth\PatientId;
use OpenEMR\AgentForge\Document\CategoryId;
use OpenEMR\AgentForge\Document\DocumentId;
use OpenEMR\AgentForge\Document\DocumentJob;
use OpenEMR\AgentForge\Document\DocumentJobId;
use OpenEMR\AgentForge\Document\DocumentJobRepository;
use OpenEMR\AgentForge\Document\DocumentRetractionReason;
use OpenEMR\AgentForge\Document\DocumentType;
use OpenEMR\AgentForge\Document\DocumentTypeMapping;
use OpenEMR\AgentForge\Document\DocumentTypeMappingRepository;
use OpenEMR\AgentForge\Document\DocumentUploadEnqueuer;
use OpenEMR\AgentForge\Document\JobStatus;
use OpenEMR\AgentForge\Observability\PatientRefHasher;
use OpenEMR\AgentForge\Observability\SensitiveLogPolicy;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use RuntimeException;

final class DocumentUploadEnqueuerTest extends TestCase
{
    public function testMappedActiveCategoryCreatesOnePendingJob(): void
    {
        $mappings = InMemoryDocumentTypeMappingRepository::withMapping(7, DocumentType::LabPdf, true);
        $jobs = new InMemoryDocumentJobRepository();
        $logger = new DocumentRecordingLogger();
        $hasher = new PatientRefHasher('test-salt');

        $jobId = (new DocumentUploadEnqueuer($mappings, $jobs, $logger, $hasher))->enqueueIfEligible(
            new PatientId(900001),
            new DocumentId(123),
            new CategoryId(7),
        );

        $this->assertSame(1, $jobId?->value);
        $this->assertCount(1, $jobs->jobs);
        $job = $jobs->jobs[0];
        $this->assertSame(JobStatus::Pending, $job->status);
        $this->assertSame(0, $job->attempts);
        $this->assertNull($job->lockToken);
        $this->assertCount(1, $logger->records);
        $this->assertSame('info', $logger->records[0]['level']);
        $this->assertSame('clinical_document.job.enqueued', $logger->records[0]['message']);
        $this->assertSame($hasher->hash(new PatientId(900001)), $logger->records[0]['context']['patient_ref']);
        $this->assertArrayNotHasKey('patient_id', $logger->records[0]['context']);
    }

    #[DataProvider('documentTypeProvider')]
    public function testMappedActiveCategoryCreatesPendingJobForEveryKnownDocumentType(DocumentType $docType): void
    {
        $mappings = InMemoryDocumentTypeMappingRepository::withMapping(7, $docType, true);
        $jobs = new InMemoryDocumentJobRepository();

        $jobId = (new DocumentUploadEnqueuer(
            $mappings,
            $jobs,
            new DocumentRecordingLogger(),
            new PatientRefHasher('test-salt'),
        ))->enqueueIfEligible(new PatientId(900001), new DocumentId(123), new CategoryId(7));

        $this->assertSame(1, $jobId?->value);
        $this->assertCount(1, $jobs->jobs);
        $this->assertSame($docType, $jobs->jobs[0]->docType);
        $this->assertSame(JobStatus::Pending, $jobs->jobs[0]->status);
    }

    public function testInactiveMappingDoesNotEnqueue(): void
    {
        $jobs = new InMemoryDocumentJobRepository();
        $logger = new DocumentRecordingLogger();

        $jobId = (new DocumentUploadEnqueuer(
            InMemoryDocumentTypeMappingRepository::withMapping(7, DocumentType::LabPdf, false),
            $jobs,
            $logger,
            new PatientRefHasher('test-salt'),
        ))->enqueueIfEligible(new PatientId(900001), new DocumentId(123), new CategoryId(7));

        $this->assertNull($jobId);
        $this->assertSame([], $jobs->jobs);
        $this->assertSame([], $logger->records);
    }

    public function testUnmappedCategoryDoesNotEnqueue(): void
    {
        $jobs = new InMemoryDocumentJobRepository();
        $logger = new DocumentRecordingLogger();

        $jobId = (new DocumentUploadEnqueuer(
            new InMemoryDocumentTypeMappingRepository(),
            $jobs,
            $logger,
            new PatientRefHasher('test-salt'),
        ))->enqueueIfEligible(new PatientId(900001), new DocumentId(123), new CategoryId(7));

        $this->assertNull($jobId);
        $this->assertSame([], $jobs->jobs);
        $this->assertSame([], $logger->records);
    }

    public function testDuplicateEnqueueReturnsSameJobId(): void
    {
        $jobs = new InMemoryDocumentJobRepository();
        $logger = new DocumentRecordingLogger();
        $enqueuer = new DocumentUploadEnqueuer(
            InMemoryDocumentTypeMappingRepository::withMapping(7, DocumentType::LabPdf, true),
            $jobs,
            $logger,
            new PatientRefHasher('test-salt'),
        );

        $first = $enqueuer->enqueueIfEligible(new PatientId(900001), new DocumentId(123), new CategoryId(7));
        $second = $enqueuer->enqueueIfEligible(new PatientId(900001), new DocumentId(123), new CategoryId(7));

        $this->assertSame($first?->value, $second?->value);
        $this->assertCount(1, $jobs->jobs);
        $this->assertCount(2, $logger->records);
        $this->assertSame($logger->records[0]['context']['job_id'], $logger->records[1]['context']['job_id']);
    }

    #[DataProvider('documentTypeProvider')]
    public function testDuplicateEnqueueReturnsSameJobIdForEveryKnownDocumentType(DocumentType $docType): void
    {
        $jobs = new InMemoryDocumentJobRepository();
        $enqueuer = new DocumentUploadEnqueuer(
            InMemoryDocumentTypeMappingRepository::withMapping(7, $docType, true),
            $jobs,
            new DocumentRecordingLogger(),
            new PatientRefHasher('test-salt'),
        );

        $first = $enqueuer->enqueueIfEligible(new PatientId(900001), new DocumentId(123), new CategoryId(7));
        $second = $enqueuer->enqueueIfEligible(new PatientId(900001), new DocumentId(123), new CategoryId(7));

        $this->assertSame($first?->value, $second?->value);
        $this->assertCount(1, $jobs->jobs);
        $this->assertSame($docType, $jobs->jobs[0]->docType);
    }

    public function testMappingRepositoryFailureIsCaughtAndLogged(): void
    {
        $logger = new DocumentRecordingLogger();

        $jobId = (new DocumentUploadEnqueuer(
            new ThrowingDocumentTypeMappingRepository(),
            new InMemoryDocumentJobRepository(),
            $logger,
            new PatientRefHasher('test-salt'),
        ))->enqueueIfEligible(new PatientId(900001), new DocumentId(123), new CategoryId(7));

        $this->assertNull($jobId);
        $this->assertSame('error', $logger->records[0]['level']);
        $this->assertSame(RuntimeException::class, $logger->records[0]['context']['error_code']);
    }

    public function testJobRepositoryFailureIsCaughtAndLogged(): void
    {
        $logger = new DocumentRecordingLogger();

        $jobId = (new DocumentUploadEnqueuer(
            InMemoryDocumentTypeMappingRepository::withMapping(7, DocumentType::LabPdf, true),
            new ThrowingDocumentJobRepository(),
            $logger,
            new PatientRefHasher('test-salt'),
        ))->enqueueIfEligible(new PatientId(900001), new DocumentId(123), new CategoryId(7));

        $this->assertNull($jobId);
        $this->assertSame('error', $logger->records[0]['level']);
        $this->assertSame(RuntimeException::class, $logger->records[0]['context']['error_code']);
    }

    public function testJobRepositoryValidationFailureIsCaughtAndLogged(): void
    {
        $logger = new DocumentRecordingLogger();

        $jobId = (new DocumentUploadEnqueuer(
            InMemoryDocumentTypeMappingRepository::withMapping(7, DocumentType::LabPdf, true),
            new InvalidDocumentJobRepository(),
            $logger,
            new PatientRefHasher('test-salt'),
        ))->enqueueIfEligible(new PatientId(900001), new DocumentId(123), new CategoryId(7));

        $this->assertNull($jobId);
        $this->assertSame('error', $logger->records[0]['level']);
        $this->assertSame(InvalidArgumentException::class, $logger->records[0]['context']['error_code']);
    }

    public function testLoggedContextUsesOnlySensitiveLogPolicyAllowedKeys(): void
    {
        $logger = new DocumentRecordingLogger();

        (new DocumentUploadEnqueuer(
            InMemoryDocumentTypeMappingRepository::withMapping(7, DocumentType::LabPdf, true),
            new InMemoryDocumentJobRepository(),
            $logger,
            new PatientRefHasher('test-salt'),
        ))->enqueueIfEligible(new PatientId(900001), new DocumentId(123), new CategoryId(7));

        $context = $logger->records[0]['context'];

        $this->assertSame($context, SensitiveLogPolicy::sanitizeContext($context));
    }

    public function testPatientRefIsHashedNotRawPatientId(): void
    {
        $logger = new DocumentRecordingLogger();
        $hasher = new PatientRefHasher('test-salt');

        (new DocumentUploadEnqueuer(
            InMemoryDocumentTypeMappingRepository::withMapping(7, DocumentType::LabPdf, true),
            new InMemoryDocumentJobRepository(),
            $logger,
            $hasher,
        ))->enqueueIfEligible(new PatientId(900001), new DocumentId(123), new CategoryId(7));

        $this->assertSame($hasher->hash(new PatientId(900001)), $logger->records[0]['context']['patient_ref']);
        $this->assertNotSame('900001', $logger->records[0]['context']['patient_ref']);
    }

    /**
     * @return array<string, array{DocumentType}>
     *
     * @codeCoverageIgnore Data providers run before coverage instrumentation starts.
     */
    public static function documentTypeProvider(): array
    {
        $cases = [];
        foreach (DocumentType::cases() as $type) {
            $cases[$type->value] = [$type];
        }

        return $cases;
    }
}

final class InMemoryDocumentTypeMappingRepository implements DocumentTypeMappingRepository
{
    /** @var array<int, DocumentTypeMapping> */
    private array $mappings = [];

    public static function withMapping(int $categoryId, DocumentType $type, bool $active): self
    {
        $repository = new self();
        $repository->mappings[$categoryId] = new DocumentTypeMapping(
            id: 1,
            categoryId: new CategoryId($categoryId),
            docType: $type,
            active: $active,
            createdAt: new DateTimeImmutable('2026-05-05T00:00:00+00:00'),
        );

        return $repository;
    }

    public function findActiveByCategoryId(CategoryId $categoryId): ?DocumentTypeMapping
    {
        $mapping = $this->mappings[$categoryId->value] ?? null;

        return $mapping?->active === true ? $mapping : null;
    }
}

final class InMemoryDocumentJobRepository implements DocumentJobRepository
{
    /** @var list<DocumentJob> */
    public array $jobs = [];

    public function enqueue(PatientId $patientId, DocumentId $documentId, DocumentType $docType): DocumentJobId
    {
        $existing = $this->findOneByUniqueKey($patientId, $documentId, $docType);
        if ($existing?->id !== null) {
            return $existing->id;
        }

        $id = new DocumentJobId(count($this->jobs) + 1);
        $this->jobs[] = new DocumentJob(
            id: $id,
            patientId: $patientId,
            documentId: $documentId,
            docType: $docType,
            status: JobStatus::Pending,
            attempts: 0,
            lockToken: null,
            createdAt: new DateTimeImmutable('2026-05-05T00:00:00+00:00'),
            startedAt: null,
            finishedAt: null,
            errorCode: null,
            errorMessage: null,
            retractedAt: null,
            retractionReason: null,
        );

        return $id;
    }

    public function findById(DocumentJobId $id): ?DocumentJob
    {
        foreach ($this->jobs as $job) {
            if ($job->id?->value === $id->value) {
                return $job;
            }
        }

        return null;
    }

    public function retractByDocument(DocumentId $documentId, DocumentRetractionReason $reason): int
    {
        $retracted = 0;
        foreach ($this->jobs as $index => $job) {
            if ($job->documentId->value !== $documentId->value || $job->status === JobStatus::Retracted) {
                continue;
            }

            $this->jobs[$index] = new DocumentJob(
                id: $job->id,
                patientId: $job->patientId,
                documentId: $job->documentId,
                docType: $job->docType,
                status: JobStatus::Retracted,
                attempts: $job->attempts,
                lockToken: null,
                createdAt: $job->createdAt,
                startedAt: $job->startedAt,
                finishedAt: $job->finishedAt ?? new DateTimeImmutable('2026-05-05T00:00:00+00:00'),
                errorCode: $job->errorCode,
                errorMessage: $job->errorMessage,
                retractedAt: new DateTimeImmutable('2026-05-05T00:00:00+00:00'),
                retractionReason: $reason,
            );
            $retracted++;
        }

        return $retracted;
    }

    public function findOneByUniqueKey(PatientId $patientId, DocumentId $documentId, DocumentType $docType): ?DocumentJob
    {
        foreach ($this->jobs as $job) {
            if (
                $job->patientId->value === $patientId->value
                && $job->documentId->value === $documentId->value
                && $job->docType === $docType
            ) {
                return $job;
            }
        }

        return null;
    }

    public function markFinished(DocumentJobId $id, JobStatus $terminal, ?string $errorCode, ?string $errorMessage): void
    {
    }

    public function findClaimedByLockToken(\OpenEMR\AgentForge\Document\Worker\LockToken $lockToken): ?DocumentJob
    {
        foreach ($this->jobs as $job) {
            if ($job->lockToken === $lockToken->value) {
                return $job;
            }
        }

        return null;
    }
}

final class ThrowingDocumentTypeMappingRepository implements DocumentTypeMappingRepository
{
    public function findActiveByCategoryId(CategoryId $categoryId): ?DocumentTypeMapping
    {
        throw new RuntimeException('mapping unavailable');
    }
}

final class ThrowingDocumentJobRepository implements DocumentJobRepository
{
    public function enqueue(PatientId $patientId, DocumentId $documentId, DocumentType $docType): DocumentJobId
    {
        throw new RuntimeException('job unavailable');
    }

    public function findById(DocumentJobId $id): ?DocumentJob
    {
        return null;
    }

    public function findOneByUniqueKey(PatientId $patientId, DocumentId $documentId, DocumentType $docType): ?DocumentJob
    {
        return null;
    }

    public function retractByDocument(DocumentId $documentId, DocumentRetractionReason $reason): int
    {
        throw new RuntimeException('job unavailable');
    }

    public function markFinished(DocumentJobId $id, JobStatus $terminal, ?string $errorCode, ?string $errorMessage): void
    {
        throw new RuntimeException('job unavailable');
    }

    public function findClaimedByLockToken(\OpenEMR\AgentForge\Document\Worker\LockToken $lockToken): ?DocumentJob
    {
        throw new RuntimeException('job unavailable');
    }
}

final class InvalidDocumentJobRepository implements DocumentJobRepository
{
    public function enqueue(PatientId $patientId, DocumentId $documentId, DocumentType $docType): DocumentJobId
    {
        throw new InvalidArgumentException('invalid job payload');
    }

    public function findById(DocumentJobId $id): ?DocumentJob
    {
        return null;
    }

    public function findOneByUniqueKey(PatientId $patientId, DocumentId $documentId, DocumentType $docType): ?DocumentJob
    {
        return null;
    }

    public function retractByDocument(DocumentId $documentId, DocumentRetractionReason $reason): int
    {
        throw new InvalidArgumentException('invalid job payload');
    }

    public function markFinished(DocumentJobId $id, JobStatus $terminal, ?string $errorCode, ?string $errorMessage): void
    {
        throw new InvalidArgumentException('invalid job payload');
    }

    public function findClaimedByLockToken(\OpenEMR\AgentForge\Document\Worker\LockToken $lockToken): ?DocumentJob
    {
        throw new InvalidArgumentException('invalid job payload');
    }
}

final class DocumentRecordingLogger extends AbstractLogger
{
    /** @var list<array{level: mixed, message: string|\Stringable, context: array<string, mixed>}> */
    public array $records = [];

    /** @param array<mixed> $context */
    public function log($level, string|\Stringable $message, array $context = []): void
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
