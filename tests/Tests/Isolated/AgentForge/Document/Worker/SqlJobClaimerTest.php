<?php

/**
 * Isolated tests for AgentForge document worker SQL claiming.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Tests\Isolated\AgentForge\Document\Worker;

use OpenEMR\AgentForge\Auth\PatientId;
use OpenEMR\AgentForge\Document\DocumentId;
use OpenEMR\AgentForge\Document\DocumentJob;
use OpenEMR\AgentForge\Document\DocumentJobId;
use OpenEMR\AgentForge\Document\DocumentType;
use OpenEMR\AgentForge\Document\JobStatus;
use OpenEMR\AgentForge\Document\Worker\DocumentJobWorkerRepository;
use OpenEMR\AgentForge\Document\Worker\LockToken;
use OpenEMR\AgentForge\Document\Worker\SqlJobClaimer;
use OpenEMR\AgentForge\Document\Worker\WorkerName;
use OpenEMR\Tests\Isolated\AgentForge\Support\FakeDatabaseExecutor;
use PHPUnit\Framework\TestCase;

final class SqlJobClaimerTest extends TestCase
{
    public function testClaimNextAtomicallyClaimsOldestPendingNonRetractedJob(): void
    {
        $lockToken = new LockToken(str_repeat('b', 64));
        $executor = new FakeDatabaseExecutor(defaultAffectedRows: 1);
        $jobs = new ClaimerDocumentJobRepository($this->runningJob($lockToken));

        $job = (new SqlJobClaimer($jobs, $executor))->claimNext(WorkerName::IntakeExtractor, $lockToken);

        $this->assertInstanceOf(DocumentJob::class, $job);
        $this->assertSame(JobStatus::Running, $job->status);
        $this->assertSame($lockToken, $jobs->findClaimedCalls[0]);
        $this->assertStringContainsString('UPDATE clinical_document_processing_jobs', $executor->statements[0]['sql']);
        $this->assertStringContainsString('SET status = ?', $executor->statements[0]['sql']);
        $this->assertStringContainsString('lock_token = ?', $executor->statements[0]['sql']);
        $this->assertStringContainsString('started_at = NOW()', $executor->statements[0]['sql']);
        $this->assertStringContainsString('attempts = attempts + 1', $executor->statements[0]['sql']);
        $this->assertStringContainsString('WHERE status = ? AND retracted_at IS NULL', $executor->statements[0]['sql']);
        $this->assertStringContainsString('ORDER BY created_at ASC', $executor->statements[0]['sql']);
        $this->assertStringContainsString('LIMIT 1', $executor->statements[0]['sql']);
        $this->assertStringNotContainsString('SKIP LOCKED', $executor->statements[0]['sql']);
        $this->assertSame(['running', $lockToken->value, 'pending'], $executor->statements[0]['binds']);
    }

    public function testClaimNextReturnsNullWithoutRefetchWhenNoRowClaimed(): void
    {
        $lockToken = new LockToken(str_repeat('c', 64));
        $executor = new FakeDatabaseExecutor(defaultAffectedRows: 0);
        $jobs = new ClaimerDocumentJobRepository(null);

        $job = (new SqlJobClaimer($jobs, $executor))->claimNext(WorkerName::IntakeExtractor, $lockToken);

        $this->assertNull($job);
        $this->assertCount(1, $executor->statements);
        $this->assertSame([], $jobs->findClaimedCalls);
    }

    public function testOnlyIntakeExtractorClaimsExtractionJobs(): void
    {
        $lockToken = new LockToken(str_repeat('d', 64));
        $executor = new FakeDatabaseExecutor(defaultAffectedRows: 1);
        $jobs = new ClaimerDocumentJobRepository($this->runningJob($lockToken));

        $job = (new SqlJobClaimer($jobs, $executor))->claimNext(WorkerName::EvidenceRetriever, $lockToken);

        $this->assertNull($job);
        $this->assertSame([], $executor->statements);
        $this->assertSame([], $jobs->findClaimedCalls);
    }

    private function runningJob(LockToken $lockToken): DocumentJob
    {
        return new DocumentJob(
            id: new DocumentJobId(9),
            patientId: new PatientId(900001),
            documentId: new DocumentId(123),
            docType: DocumentType::LabPdf,
            status: JobStatus::Running,
            attempts: 1,
            lockToken: $lockToken->value,
            createdAt: new \DateTimeImmutable('2026-05-05 00:00:00'),
            startedAt: new \DateTimeImmutable('2026-05-05 00:01:00'),
            finishedAt: null,
            errorCode: null,
            errorMessage: null,
            retractedAt: null,
            retractionReason: null,
        );
    }
}

final class ClaimerDocumentJobRepository implements DocumentJobWorkerRepository
{
    /** @var list<LockToken> */
    public array $findClaimedCalls = [];

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
        return 1;
    }

    public function findClaimedByLockToken(LockToken $lockToken): ?DocumentJob
    {
        $this->findClaimedCalls[] = $lockToken;

        return $this->claimedJob;
    }
}
