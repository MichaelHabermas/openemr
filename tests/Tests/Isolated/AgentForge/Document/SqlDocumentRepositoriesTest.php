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
use OpenEMR\AgentForge\Document\CategoryId;
use OpenEMR\AgentForge\Document\DocumentId;
use OpenEMR\AgentForge\Document\DocumentJob;
use OpenEMR\AgentForge\Document\DocumentJobId;
use OpenEMR\AgentForge\Document\DocumentRetractionReason;
use OpenEMR\AgentForge\Document\DocumentType;
use OpenEMR\AgentForge\Document\JobStatus;
use OpenEMR\AgentForge\Document\Retraction\SqlDocumentRetractionRepository;
use OpenEMR\AgentForge\Document\SqlDocumentJobRepository;
use OpenEMR\AgentForge\Document\SqlDocumentTypeMappingRepository;
use OpenEMR\AgentForge\Document\Worker\LockToken;
use OpenEMR\Tests\Isolated\AgentForge\Support\FakeDatabaseExecutor;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use RuntimeException;
use Stringable;

final class SqlDocumentRepositoriesTest extends TestCase
{
    public function testMappingRepositorySelectsActiveCategoryMapping(): void
    {
        $executor = new FakeDatabaseExecutor(records:[
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
        $this->assertStringContainsString('FROM clinical_document_type_mappings', $executor->reads[0]['sql']);
        $this->assertSame([7], $executor->reads[0]['binds']);
    }

    public function testMappingRepositoryReturnsNullAndLogsWarningForUnknownDocType(): void
    {
        $executor = new FakeDatabaseExecutor(records:[
            [
                'id' => 3,
                'category_id' => 7,
                'doc_type' => 'unknown_type',
                'active' => 1,
                'created_at' => '2026-05-05 00:00:00',
            ],
        ]);
        $logger = new SqlDocumentRecordingLogger();

        $mapping = (new SqlDocumentTypeMappingRepository($executor, $logger))->findActiveByCategoryId(new CategoryId(7));

        $this->assertNull($mapping);
        $this->assertSame('warning', $logger->records[0]['level']);
        $this->assertSame('clinical_document.mapping.invalid', $logger->records[0]['message']);
        $this->assertSame(7, $logger->records[0]['context']['category_id']);
        $this->assertArrayNotHasKey('doc_type', $logger->records[0]['context']);
    }

    #[DataProvider('documentTypeProvider')]
    public function testJobRepositoryEnqueueUsesInsertIgnoreThenSelectsUniqueJob(DocumentType $docType): void
    {
        $executor = new FakeDatabaseExecutor(records:[
            [
                'id' => 9,
                'patient_id' => 900001,
                'document_id' => 123,
                'doc_type' => $docType->value,
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
            $docType,
        );

        $this->assertSame(9, $jobId->value);
        $this->assertStringContainsString('INSERT IGNORE INTO clinical_document_processing_jobs', $executor->statements[0]['sql']);
        $this->assertSame([900001, 123, $docType->value, 'pending'], $executor->statements[0]['binds']);
        $this->assertStringContainsString('WHERE patient_id = ? AND document_id = ? AND doc_type = ?', $executor->reads[0]['sql']);
    }

    public function testJobRepositoryFindByIdHydratesJob(): void
    {
        $executor = new FakeDatabaseExecutor(records:[
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

    public function testRetractionRepositoryRetractsAllJobsForDocumentAndWritesAuditRows(): void
    {
        $executor = new FakeDatabaseExecutor(records: [], defaultAffectedRows: 2);

        $count = (new SqlDocumentRetractionRepository($executor))->retractByDocument(
            new DocumentId(123),
            DocumentRetractionReason::SourceDocumentDeleted,
        );

        $this->assertSame(2, $count);
        $this->assertCount(20, $executor->statements);
        $this->assertSame('START TRANSACTION', $executor->statements[0]['sql']);
        $this->assertStringContainsString('INSERT INTO clinical_document_retractions', $executor->statements[1]['sql']);
        $this->assertStringContainsString('JSON_OBJECT', $executor->statements[1]['sql']);
        $this->assertContains('retract_job', $executor->statements[1]['binds']);
        $this->assertStringContainsString('UPDATE clinical_document_processing_jobs', $executor->statements[2]['sql']);
        $this->assertStringContainsString('SET status = ?', $executor->statements[2]['sql']);
        $this->assertStringContainsString('retracted_at = NOW()', $executor->statements[2]['sql']);
        $this->assertStringContainsString('retraction_reason = ?', $executor->statements[2]['sql']);
        $this->assertStringContainsString('finished_at = COALESCE(finished_at, NOW())', $executor->statements[2]['sql']);
        $this->assertStringContainsString('lock_token = NULL', $executor->statements[2]['sql']);
        $this->assertStringContainsString('WHERE document_id = ? AND status <> ?', $executor->statements[2]['sql']);
        $this->assertSame(['retracted', 'source_document_deleted', 123, 'retracted'], $executor->statements[2]['binds']);
        $this->assertContains('retract_promoted_row', $executor->statements[3]['binds']);
        $this->assertStringContainsString('INNER JOIN clinical_document_promotions', $executor->statements[4]['sql']);
        $this->assertContains('retract_legacy_promoted_fact', $executor->statements[7]['binds']);
        $this->assertStringContainsString('INNER JOIN clinical_document_promoted_facts', $executor->statements[8]['sql']);
        $this->assertContains('retract_promotion', $executor->statements[10]['binds']);
        $this->assertStringContainsString('WHERE document_id = ? AND active = 1', $executor->statements[11]['sql']);
        $this->assertContains('retract_fact', $executor->statements[13]['binds']);
        $this->assertStringContainsString('UPDATE clinical_document_facts', $executor->statements[14]['sql']);
        $this->assertStringContainsString('retracted_at = COALESCE(retracted_at, NOW())', $executor->statements[14]['sql']);
        $this->assertStringContainsString('deactivated_at = COALESCE(deactivated_at, NOW())', $executor->statements[14]['sql']);
        $this->assertContains('deactivate_embedding', $executor->statements[15]['binds']);
        $this->assertStringContainsString('UPDATE clinical_document_fact_embeddings e', $executor->statements[16]['sql']);
        $this->assertContains('scrub_identity_check', $executor->statements[17]['binds']);
        $this->assertStringContainsString('UPDATE clinical_document_identity_checks', $executor->statements[18]['sql']);
        $this->assertStringContainsString('extracted_identifiers_json = NULL', $executor->statements[18]['sql']);
        $this->assertSame('COMMIT', $executor->statements[19]['sql']);
    }

    public function testRetractionRepositoryCleanupRunsEvenWhenJobAlreadyRetracted(): void
    {
        $executor = new FakeDatabaseExecutor(records: [], defaultAffectedRows: 0);

        $count = (new SqlDocumentRetractionRepository($executor))->retractByDocument(
            new DocumentId(123),
            DocumentRetractionReason::SourceDocumentDeleted,
        );

        $this->assertSame(0, $count);
        $this->assertCount(20, $executor->statements);
        $this->assertSame('START TRANSACTION', $executor->statements[0]['sql']);
        $this->assertStringContainsString('INSERT INTO clinical_document_retractions', $executor->statements[1]['sql']);
        $this->assertStringContainsString('UPDATE clinical_document_processing_jobs', $executor->statements[2]['sql']);
        $this->assertStringContainsString('INNER JOIN clinical_document_promotions', $executor->statements[4]['sql']);
        $this->assertStringContainsString('INNER JOIN clinical_document_promotions', $executor->statements[6]['sql']);
        $this->assertStringContainsString('INNER JOIN clinical_document_promoted_facts', $executor->statements[8]['sql']);
        $this->assertStringContainsString('INNER JOIN clinical_document_promoted_facts', $executor->statements[9]['sql']);
        $this->assertStringContainsString('WHERE document_id = ? AND active = 1', $executor->statements[11]['sql']);
        $this->assertStringContainsString('WHERE document_id = ? AND promotion_status <> ?', $executor->statements[12]['sql']);
        $this->assertStringContainsString('UPDATE clinical_document_facts', $executor->statements[14]['sql']);
        $this->assertStringContainsString('UPDATE clinical_document_fact_embeddings e', $executor->statements[16]['sql']);
        $this->assertContains('scrub_identity_check', $executor->statements[17]['binds']);
        $this->assertStringContainsString('UPDATE clinical_document_identity_checks', $executor->statements[18]['sql']);
        $this->assertSame('COMMIT', $executor->statements[19]['sql']);
    }

    public function testRetractionRepositoryRollsBackWhenCleanupFails(): void
    {
        $executor = new FakeDatabaseExecutor(records: [], defaultAffectedRows: 0, throwOnSql: 'UPDATE clinical_document_processing_jobs');

        try {
            (new SqlDocumentRetractionRepository($executor))->retractByDocument(
                new DocumentId(123),
                DocumentRetractionReason::SourceDocumentDeleted,
            );
            $this->fail('Expected retraction SQL failure to be rethrown.');
        } catch (RuntimeException $runtimeException) {
            $this->assertSame('synthetic SQL failure', $runtimeException->getMessage());
        }

        $this->assertSame('START TRANSACTION', $executor->statements[0]['sql']);
        $this->assertStringContainsString('INSERT INTO clinical_document_retractions', $executor->statements[1]['sql']);
        $this->assertStringContainsString('UPDATE clinical_document_processing_jobs', $executor->statements[2]['sql']);
        $this->assertSame('ROLLBACK', $executor->statements[3]['sql']);
    }

    public function testJobRepositoryDelegatesRetractionToAuditedRetractionRepository(): void
    {
        $executor = new FakeDatabaseExecutor(records: [], defaultAffectedRows: 2);

        $count = (new SqlDocumentJobRepository($executor))->retractByDocument(
            new DocumentId(123),
            DocumentRetractionReason::SourceDocumentDeleted,
        );

        $this->assertSame(2, $count);
        $this->assertStringContainsString('INSERT INTO clinical_document_retractions', $executor->statements[1]['sql']);
        $this->assertStringContainsString('UPDATE clinical_document_processing_jobs', $executor->statements[2]['sql']);
    }

    public function testJobRepositoryMarkFinishedReleasesClaim(): void
    {
        $executor = new FakeDatabaseExecutor(records:[]);

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
        $executor = new FakeDatabaseExecutor(records:[
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
        $this->assertStringContainsString('WHERE lock_token = ? AND status = ?', $executor->reads[0]['sql']);
        $this->assertSame([$lockToken->value, 'running'], $executor->reads[0]['binds']);
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

final class SqlDocumentRecordingLogger extends AbstractLogger
{
    /** @var list<array{level: mixed, message: string|Stringable, context: array<mixed>}> */
    public array $records = [];

    /** @param array<mixed> $context */
    public function log($level, string | Stringable $message, array $context = []): void
    {
        $this->records[] = [
            'level' => $level,
            'message' => $message,
            'context' => $context,
        ];
    }
}
