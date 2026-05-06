<?php

/**
 * Isolated tests for AgentForge supervisor handoff SQL shape.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Tests\Isolated\AgentForge\Orchestration;

use DateTimeImmutable;
use OpenEMR\AgentForge\Auth\PatientId;
use OpenEMR\AgentForge\Document\DocumentId;
use OpenEMR\AgentForge\Document\DocumentJob;
use OpenEMR\AgentForge\Document\DocumentJobId;
use OpenEMR\AgentForge\Document\DocumentType;
use OpenEMR\AgentForge\Document\JobStatus;
use OpenEMR\AgentForge\Document\Worker\WorkerName;
use OpenEMR\AgentForge\Orchestration\SqlSupervisorHandoffRepository;
use OpenEMR\AgentForge\Orchestration\SupervisorDecision;
use OpenEMR\Tests\Isolated\AgentForge\Support\FakeDatabaseExecutor;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class SqlSupervisorHandoffRepositoryTest extends TestCase
{
    public function testRecordUsesSafeInsertShape(): void
    {
        $executor = new FakeDatabaseExecutor(defaultInsertId: 77);
        $decision = SupervisorDecision::handoff(
            WorkerName::EvidenceRetriever,
            'trusted_document_ready_for_evidence',
            ['job_status' => 'succeeded', 'trusted_for_evidence' => 1],
        );

        $id = (new SqlSupervisorHandoffRepository($executor))->record(
            $this->persistedJob(),
            $decision,
            'request-123',
            42,
        );

        $this->assertSame(77, $id);
        $this->assertCount(1, $executor->statements);
        $this->assertStringContainsString('INSERT INTO clinical_supervisor_handoffs', $executor->statements[0]['sql']);
        $this->assertStringContainsString(
            '(request_id, job_id, source_node, destination_node, decision_reason, task_type, outcome, latency_ms, error_reason, created_at)',
            $executor->statements[0]['sql'],
        );
        $this->assertSame(
            [
                'request-123',
                9,
                'supervisor',
                'evidence-retriever',
                'trusted_document_ready_for_evidence',
                'lab_pdf',
                'handoff',
                42,
                null,
            ],
            $executor->statements[0]['binds'],
        );
    }

    public function testRecordRejectsUnpersistedJobs(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('persisted document job id');

        (new SqlSupervisorHandoffRepository(new FakeDatabaseExecutor(defaultInsertId: 77)))->record(
            $this->unpersistedJob(),
            SupervisorDecision::hold('document_processing_failed'),
        );
    }

    public function testRecordRejectsHoldDecisionWithoutDestination(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('destination node');

        (new SqlSupervisorHandoffRepository(new FakeDatabaseExecutor(defaultInsertId: 77)))->record(
            $this->persistedJob(),
            SupervisorDecision::hold('document_processing_failed'),
        );
    }

    public function testRecordRequestHandoffSupportsIntakeExtractorRoute(): void
    {
        $executor = new FakeDatabaseExecutor(defaultInsertId: 77);

        $id = (new SqlSupervisorHandoffRepository($executor))->recordRequestHandoff(
            'request-456',
            WorkerName::IntakeExtractor,
            'document_extraction_required',
            'intake_pdf',
            'handoff',
            7,
            null,
        );

        $this->assertSame(77, $id);
        $this->assertSame(
            [
                'request-456',
                null,
                'supervisor',
                'intake-extractor',
                'document_extraction_required',
                'intake_pdf',
                'handoff',
                7,
                null,
            ],
            $executor->statements[0]['binds'],
        );
    }

    private function persistedJob(): DocumentJob
    {
        return $this->job(new DocumentJobId(9));
    }

    private function unpersistedJob(): DocumentJob
    {
        return $this->job(null);
    }

    private function job(?DocumentJobId $id): DocumentJob
    {
        return new DocumentJob(
            id: $id,
            patientId: new PatientId(900001),
            documentId: new DocumentId(123),
            docType: DocumentType::LabPdf,
            status: JobStatus::Succeeded,
            attempts: 1,
            lockToken: null,
            createdAt: new DateTimeImmutable('2026-05-05 00:00:00'),
            startedAt: new DateTimeImmutable('2026-05-05 00:01:00'),
            finishedAt: new DateTimeImmutable('2026-05-05 00:02:00'),
            errorCode: null,
            errorMessage: null,
            retractedAt: null,
            retractionReason: null,
        );
    }
}
