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
use OpenEMR\AgentForge\Orchestration\DecisionType;
use OpenEMR\AgentForge\Orchestration\HandoffDecision;
use OpenEMR\AgentForge\Orchestration\NodeName;
use OpenEMR\AgentForge\Orchestration\Policy\DefaultHandoffPolicy;
use OpenEMR\AgentForge\Orchestration\Supervisor;
use OpenEMR\AgentForge\Orchestration\SupervisorHandoffRepository;
use OpenEMR\AgentForge\Orchestration\SupervisorRuntime;
use OpenEMR\AgentForge\Support\FrozenMonotonicClock;
use PHPUnit\Framework\TestCase;

final class SupervisorRuntimeTest extends TestCase
{
    public function testRouteDocumentJobReturnsGuidelineDecisionForTrustedJob(): void
    {
        $clock = new FrozenMonotonicClock(1000);
        $handoffs = new RecordingSupervisorHandoffRepository();
        $runtime = new SupervisorRuntime(
            new Supervisor(new DefaultHandoffPolicy(), $handoffs, $clock),
        );
        $job = $this->job(JobStatus::Succeeded);

        $decision = $runtime->routeDocumentJob($job, new PatientId(1), true);

        $this->assertSame(DecisionType::Guideline, $decision->type);
        $this->assertSame(NodeName::EvidenceRetriever, $decision->targetNode);
    }

    public function testRouteDocumentJobReturnsExtractDecisionForPendingJob(): void
    {
        $clock = new FrozenMonotonicClock(1000);
        $handoffs = new RecordingSupervisorHandoffRepository();
        $runtime = new SupervisorRuntime(
            new Supervisor(new DefaultHandoffPolicy(), $handoffs, $clock),
        );
        $job = $this->job(JobStatus::Pending);

        $decision = $runtime->routeDocumentJob($job, new PatientId(1), false);

        $this->assertSame(DecisionType::Extract, $decision->type);
        $this->assertSame(NodeName::IntakeExtractor, $decision->targetNode);
    }

    public function testRouteChatRequestReturnsGuidelineDecisionForGuidelineQuestion(): void
    {
        $clock = new FrozenMonotonicClock(1000);
        $handoffs = new RecordingSupervisorHandoffRepository();
        $runtime = new SupervisorRuntime(
            new Supervisor(new DefaultHandoffPolicy(), $handoffs, $clock),
        );

        $decision = $runtime->routeChatRequest(
            new \OpenEMR\AgentForge\Handlers\AgentQuestion('What guideline should I follow?'),
            new PatientId(1),
            'general',
        );

        $this->assertSame(DecisionType::Guideline, $decision->type);
    }

    public function testRouteChatRequestReturnsRefuseDecisionForCrossPatientQuery(): void
    {
        $clock = new FrozenMonotonicClock(1000);
        $handoffs = new RecordingSupervisorHandoffRepository();
        $runtime = new SupervisorRuntime(
            new Supervisor(new DefaultHandoffPolicy(), $handoffs, $clock),
        );

        $decision = $runtime->routeChatRequest(
            new \OpenEMR\AgentForge\Handlers\AgentQuestion('Compare to other patient'),
            new PatientId(1),
            'general',
        );

        $this->assertSame(DecisionType::Refuse, $decision->type);
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
    /** @var list<array{request_id: string, destination_node: NodeName, decision_reason: string, latency_ms: ?int}> */
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
        $this->records[] = [
            'request_id' => $requestId,
            'destination_node' => $destinationNode,
            'decision_reason' => $decisionReason,
            'latency_ms' => $latencyMs,
        ];

        return count($this->records);
    }

    public function record(
        DocumentJob $job,
        \OpenEMR\AgentForge\Orchestration\SupervisorDecision $decision,
        ?string $requestId = null,
        ?int $latencyMs = null,
        ?string $errorReason = null,
    ): int {
        // Legacy method not used in new API
        return 1;
    }
}
