<?php

/**
 * Isolated tests for the supervisor runtime decision/audit boundary.
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
use OpenEMR\AgentForge\Orchestration\NodeName;
use OpenEMR\AgentForge\Orchestration\Supervisor;
use OpenEMR\AgentForge\Orchestration\SupervisorDecision;
use OpenEMR\AgentForge\Orchestration\SupervisorHandoffRepository;
use OpenEMR\AgentForge\Orchestration\SupervisorRuntime;
use PHPUnit\Framework\TestCase;

final class SupervisorRuntimeTest extends TestCase
{
    public function testInspectAndRecordPersistsHandoffDecision(): void
    {
        $handoffs = new RecordingSupervisorHandoffRepository();
        $runtime = new SupervisorRuntime(new Supervisor(), $handoffs);
        $job = $this->job(JobStatus::Succeeded);

        $decision = $runtime->inspect($job, true);
        $id = $runtime->record($job, $decision, 'request-1', 12);

        $this->assertSame(1, $id);
        $this->assertSame(NodeName::EvidenceRetriever, $decision->targetNode);
        $this->assertCount(1, $handoffs->records);
        $this->assertSame($job, $handoffs->records[0]['job']);
        $this->assertSame($decision, $handoffs->records[0]['decision']);
        $this->assertSame('request-1', $handoffs->records[0]['request_id']);
        $this->assertSame(12, $handoffs->records[0]['latency_ms']);
    }

    public function testHoldDecisionDoesNotCreateHandoffRow(): void
    {
        $handoffs = new RecordingSupervisorHandoffRepository();
        $runtime = new SupervisorRuntime(new Supervisor(), $handoffs);
        $job = $this->job(JobStatus::Succeeded);

        $decision = $runtime->inspect($job, false);

        $this->assertNull($runtime->record($job, $decision));
        $this->assertSame([], $handoffs->records);
    }

    private function job(JobStatus $status): DocumentJob
    {
        return new DocumentJob(
            id: new DocumentJobId(9),
            patientId: new PatientId(900001),
            documentId: new DocumentId(123),
            docType: DocumentType::LabPdf,
            status: $status,
            attempts: 0,
            lockToken: null,
            createdAt: new DateTimeImmutable('2026-05-05 00:00:00'),
            startedAt: null,
            finishedAt: null,
            errorCode: null,
            errorMessage: null,
            retractedAt: null,
            retractionReason: null,
        );
    }
}

final class RecordingSupervisorHandoffRepository implements SupervisorHandoffRepository
{
    /** @var list<array{job: DocumentJob, decision: SupervisorDecision, request_id: ?string, latency_ms: ?int}> */
    public array $records = [];

    public function recordRequestHandoff(
        string $requestId,
        NodeName $destinationNode,
        string $decisionReason,
        string $taskType,
        string $outcome,
        ?int $latencyMs = null,
        ?string $errorReason = null,
    ): int {
        return 1;
    }

    public function record(
        DocumentJob $job,
        SupervisorDecision $decision,
        ?string $requestId = null,
        ?int $latencyMs = null,
        ?string $errorReason = null,
    ): int {
        $this->records[] = [
            'job' => $job,
            'decision' => $decision,
            'request_id' => $requestId,
            'latency_ms' => $latencyMs,
        ];

        return count($this->records);
    }
}
