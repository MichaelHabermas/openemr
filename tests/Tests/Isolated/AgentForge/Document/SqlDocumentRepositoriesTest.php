<?php

/**
 * Isolated tests for AgentForge document SQL repository query shape.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Tests\Isolated\AgentForge\Document;

use OpenEMR\AgentForge\Auth\PatientId;
use OpenEMR\AgentForge\DatabaseExecutor;
use OpenEMR\AgentForge\Deadline;
use OpenEMR\AgentForge\Document\CategoryId;
use OpenEMR\AgentForge\Document\DocumentId;
use OpenEMR\AgentForge\Document\DocumentJob;
use OpenEMR\AgentForge\Document\DocumentJobId;
use OpenEMR\AgentForge\Document\DocumentRetractionReason;
use OpenEMR\AgentForge\Document\DocumentType;
use OpenEMR\AgentForge\Document\JobStatus;
use OpenEMR\AgentForge\Document\SqlDocumentJobRepository;
use OpenEMR\AgentForge\Document\SqlDocumentTypeMappingRepository;
use OpenEMR\AgentForge\Document\Worker\LockToken;
use PHPUnit\Framework\TestCase;

final class SqlDocumentRepositoriesTest extends TestCase
{
    public function testMappingRepositorySelectsActiveCategoryMapping(): void
    {
        $executor = new DocumentRepositoryExecutor([
            [
                'id' => 3,
                'category_id' => 7,
                'doc_type' => 'lab_pdf',
                'active' => 1,
                'created_at' => '2026-05-05 00:00:00',
            ],
        ]);

        $mapping = (new SqlDocumentTypeMappingRepository($executor))->findActiveByCategoryId(new CategoryId(7));

        $this->assertSame(DocumentType::LabPdf, $mapping?->docType);
        $this->assertStringContainsString('FROM clinical_document_type_mappings', $executor->queries[0]['sql']);
        $this->assertSame([7], $executor->queries[0]['binds']);
    }

    public function testJobRepositoryEnqueueUsesInsertIgnoreThenSelectsUniqueJob(): void
    {
        $executor = new DocumentRepositoryExecutor([
            [
                'id' => 9,
                'patient_id' => 900001,
                'document_id' => 123,
                'doc_type' => 'lab_pdf',
                'status' => 'pending',
                'attempts' => 0,
                'lock_token' => null,
                'created_at' => '2026-05-05 00:00:00',
                'started_at' => null,
                'finished_at' => null,
                'error_code' => null,
                'error_message' => null,
                'retracted_at' => null,
                'retraction_reason' => null,
            ],
        ]);

        $jobId = (new SqlDocumentJobRepository($executor))->enqueue(
            new PatientId(900001),
            new DocumentId(123),
            DocumentType::LabPdf,
        );

        $this->assertSame(9, $jobId->value);
        $this->assertStringContainsString('INSERT IGNORE INTO clinical_document_processing_jobs', $executor->statements[0]['sql']);
        $this->assertSame([900001, 123, 'lab_pdf', 'pending'], $executor->statements[0]['binds']);
        $this->assertStringContainsString('WHERE patient_id = ? AND document_id = ? AND doc_type = ?', $executor->queries[0]['sql']);
    }

    public function testJobRepositoryFindByIdHydratesJob(): void
    {
        $executor = new DocumentRepositoryExecutor([
            [
                'id' => 9,
                'patient_id' => 900001,
                'document_id' => 123,
                'doc_type' => 'lab_pdf',
                'status' => 'pending',
                'attempts' => 0,
                'lock_token' => null,
                'created_at' => '2026-05-05 00:00:00',
                'started_at' => null,
                'finished_at' => null,
                'error_code' => null,
                'error_message' => null,
                'retracted_at' => null,
                'retraction_reason' => null,
            ],
        ]);

        $job = (new SqlDocumentJobRepository($executor))->findById(new DocumentJobId(9));

        $this->assertInstanceOf(DocumentJob::class, $job);
        $this->assertSame(900001, $job->patientId->value);
        $this->assertSame(DocumentType::LabPdf, $job->docType);
        $this->assertSame(JobStatus::Pending, $job->status);
        $this->assertNull($job->retractedAt);
        $this->assertNull($job->retractionReason);
    }

    public function testJobRepositoryRetractsAllJobsForDocument(): void
    {
        $executor = new DocumentRepositoryExecutor([], affectedRows: 2);

        $count = (new SqlDocumentJobRepository($executor))->retractByDocument(
            new DocumentId(123),
            DocumentRetractionReason::SourceDocumentDeleted,
        );

        $this->assertSame(2, $count);
        $this->assertStringContainsString('UPDATE clinical_document_processing_jobs', $executor->statements[0]['sql']);
        $this->assertStringContainsString('SET status = ?', $executor->statements[0]['sql']);
        $this->assertStringContainsString('retracted_at = NOW()', $executor->statements[0]['sql']);
        $this->assertStringContainsString('retraction_reason = ?', $executor->statements[0]['sql']);
        $this->assertStringContainsString('finished_at = COALESCE(finished_at, NOW())', $executor->statements[0]['sql']);
        $this->assertStringContainsString('lock_token = NULL', $executor->statements[0]['sql']);
        $this->assertStringContainsString('WHERE document_id = ? AND status <> ?', $executor->statements[0]['sql']);
        $this->assertSame(['retracted', 'source_document_deleted', 123, 'retracted'], $executor->statements[0]['binds']);
    }

    public function testJobRepositoryMarkFinishedReleasesClaim(): void
    {
        $executor = new DocumentRepositoryExecutor([]);

        (new SqlDocumentJobRepository($executor))->markFinished(
            new DocumentJobId(9),
            new LockToken(str_repeat('b', 64)),
            JobStatus::Failed,
            'extraction_not_implemented',
            'M3 worker skeleton; M4 will replace this processor.',
        );

        $this->assertStringContainsString('UPDATE clinical_document_processing_jobs', $executor->statements[0]['sql']);
        $this->assertStringContainsString('SET status = ?', $executor->statements[0]['sql']);
        $this->assertStringContainsString('finished_at = NOW()', $executor->statements[0]['sql']);
        $this->assertStringContainsString('error_code = ?', $executor->statements[0]['sql']);
        $this->assertStringContainsString('error_message = ?', $executor->statements[0]['sql']);
        $this->assertStringContainsString('lock_token = NULL', $executor->statements[0]['sql']);
        $this->assertStringContainsString('WHERE id = ? AND status = ? AND lock_token = ? AND retracted_at IS NULL', $executor->statements[0]['sql']);
        $this->assertSame(
            [
                'failed',
                'extraction_not_implemented',
                'M3 worker skeleton; M4 will replace this processor.',
                9,
                'running',
                str_repeat('b', 64),
            ],
            $executor->statements[0]['binds'],
        );
    }

    public function testJobRepositoryFindClaimedByLockTokenHydratesRunningJob(): void
    {
        $lockToken = new LockToken(str_repeat('a', 64));
        $executor = new DocumentRepositoryExecutor([
            [
                'id' => 9,
                'patient_id' => 900001,
                'document_id' => 123,
                'doc_type' => 'lab_pdf',
                'status' => 'running',
                'attempts' => 1,
                'lock_token' => $lockToken->value,
                'created_at' => '2026-05-05 00:00:00',
                'started_at' => '2026-05-05 00:01:00',
                'finished_at' => null,
                'error_code' => null,
                'error_message' => null,
                'retracted_at' => null,
                'retraction_reason' => null,
            ],
        ]);

        $job = (new SqlDocumentJobRepository($executor))->findClaimedByLockToken($lockToken);

        $this->assertInstanceOf(DocumentJob::class, $job);
        $this->assertSame(9, $job->id?->value);
        $this->assertSame(JobStatus::Running, $job->status);
        $this->assertSame($lockToken->value, $job->lockToken);
        $this->assertStringContainsString('WHERE lock_token = ? AND status = ?', $executor->queries[0]['sql']);
        $this->assertSame([$lockToken->value, 'running'], $executor->queries[0]['binds']);
    }
}

final class DocumentRepositoryExecutor implements DatabaseExecutor
{
    /** @var list<array{sql: string, binds: list<mixed>}> */
    public array $queries = [];

    /** @var list<array{sql: string, binds: list<mixed>}> */
    public array $statements = [];

    /** @param list<array<string, mixed>> $records */
    public function __construct(private readonly array $records, private readonly int $affectedRows = 1)
    {
    }

    public function fetchRecords(string $sql, array $binds = [], ?Deadline $deadline = null): array
    {
        $this->queries[] = ['sql' => $sql, 'binds' => $binds];

        return $this->records;
    }

    public function executeStatement(string $sql, array $binds = []): void
    {
        $this->statements[] = ['sql' => $sql, 'binds' => $binds];
    }

    public function executeAffected(string $sql, array $binds = []): int
    {
        $this->statements[] = ['sql' => $sql, 'binds' => $binds];

        return $this->affectedRows;
    }

    public function insert(string $sql, array $binds = []): int
    {
        $this->statements[] = ['sql' => $sql, 'binds' => $binds];

        return 1;
    }
}
