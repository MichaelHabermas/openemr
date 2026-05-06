<?php

/**
 * Isolated tests for AgentForge supervisor routing.
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
use PHPUnit\Framework\TestCase;

final class SupervisorTest extends TestCase
{
    public function testRoutesUnfinishedJobsToExtractor(): void
    {
        $decision = (new Supervisor())->decide($this->job(JobStatus::Pending), false);

        $this->assertTrue($decision->shouldHandoff());
        $this->assertSame(NodeName::IntakeExtractor, $decision->targetNode);
        $this->assertSame('document_extraction_required', $decision->reason);
        $this->assertSame('pending', $decision->context['job_status']);
        $this->assertSame(0, $decision->context['trusted_for_evidence']);
    }

    public function testRoutesTrustedSucceededJobsToEvidenceRetriever(): void
    {
        $decision = (new Supervisor())->decide($this->job(JobStatus::Succeeded), true);

        $this->assertTrue($decision->shouldHandoff());
        $this->assertSame(NodeName::EvidenceRetriever, $decision->targetNode);
        $this->assertSame('trusted_document_ready_for_evidence', $decision->reason);
    }

    public function testHoldsSucceededJobsUntilIdentityIsTrusted(): void
    {
        $decision = (new Supervisor())->decide($this->job(JobStatus::Succeeded), false);

        $this->assertFalse($decision->shouldHandoff());
        $this->assertNull($decision->targetNode);
        $this->assertSame('hold', $decision->decision);
        $this->assertSame('identity_not_trusted_for_evidence', $decision->reason);
    }

    public function testHoldsRetractedJobs(): void
    {
        $decision = (new Supervisor())->decide(
            $this->job(JobStatus::Retracted, retractedAt: new DateTimeImmutable('2026-05-05 00:05:00')),
            true,
        );

        $this->assertFalse($decision->shouldHandoff());
        $this->assertSame('document_retracted', $decision->reason);
    }

    private function job(JobStatus $status, ?DateTimeImmutable $retractedAt = null): DocumentJob
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
            retractedAt: $retractedAt,
            retractionReason: null,
        );
    }
}
