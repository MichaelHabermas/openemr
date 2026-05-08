<?php

/**
 * Isolated tests for document worker factory processor wiring.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/open-emr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Tests\Isolated\AgentForge\Document\Worker;

use DateTimeImmutable;
use OpenEMR\AgentForge\Auth\PatientId;
use OpenEMR\AgentForge\Document\ClinicalDocumentIngestionWorkflow;
use OpenEMR\AgentForge\Document\DocumentId;
use OpenEMR\AgentForge\Document\DocumentJob;
use OpenEMR\AgentForge\Document\DocumentJobId;
use OpenEMR\AgentForge\Document\DocumentType;
use OpenEMR\AgentForge\Document\JobStatus;
use OpenEMR\AgentForge\Document\Worker\DocumentJobProcessor;
use OpenEMR\AgentForge\Document\Worker\DocumentJobWorkerFactory;
use OpenEMR\AgentForge\Document\Worker\DocumentLoadResult;
use OpenEMR\AgentForge\Document\Worker\ProcessingResult;
use OpenEMR\AgentForge\Document\Worker\WorkerName;
use OpenEMR\Tests\Isolated\AgentForge\Support\FakeDatabaseExecutor;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use RuntimeException;

final class DocumentJobWorkerFactoryProcessorTest extends TestCase
{
    public function testIntakeExtractorMapsToWorkflowBackedProcessor(): void
    {
        $method = new ReflectionMethod(DocumentJobWorkerFactory::class, 'processorFor');
        $executor = new FakeDatabaseExecutor();

        $processor = $method->invoke(null, WorkerName::IntakeExtractor, $executor);

        $this->assertInstanceOf(ClinicalDocumentIngestionWorkflow::class, $processor);
    }

    public function testContractOnlyJobFailsClosedWithoutProviderEnvironment(): void
    {
        $openAiProvider = getenv('AGENTFORGE_VLM_PROVIDER', true);
        $agentForgeOpenAiKey = getenv('AGENTFORGE_OPENAI_API_KEY', true);
        $openAiKey = getenv('OPENAI_API_KEY', true);
        putenv('AGENTFORGE_VLM_PROVIDER=openai');
        putenv('AGENTFORGE_OPENAI_API_KEY');
        putenv('OPENAI_API_KEY');

        try {
            $method = new ReflectionMethod(DocumentJobWorkerFactory::class, 'processorFor');
            $processor = $method->invoke(null, WorkerName::IntakeExtractor, new FakeDatabaseExecutor());
            $this->assertInstanceOf(ClinicalDocumentIngestionWorkflow::class, $processor);
            $this->assertInstanceOf(DocumentJobProcessor::class, $processor);

            $result = $processor->process(
                new DocumentJob(
                    id: new DocumentJobId(100),
                    patientId: new PatientId(200),
                    documentId: new DocumentId(300),
                    docType: DocumentType::ReferralDocx,
                    status: JobStatus::Running,
                    attempts: 1,
                    lockToken: 'lock-token',
                    createdAt: new DateTimeImmutable('2026-05-05T00:00:00+00:00'),
                    startedAt: null,
                    finishedAt: null,
                    errorCode: null,
                    errorMessage: null,
                    retractedAt: null,
                    retractionReason: null,
                ),
                new DocumentLoadResult('docx-bytes', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'referral.docx'),
            );
        } finally {
            self::restoreEnv('AGENTFORGE_VLM_PROVIDER', $openAiProvider);
            self::restoreEnv('AGENTFORGE_OPENAI_API_KEY', $agentForgeOpenAiKey);
            self::restoreEnv('OPENAI_API_KEY', $openAiKey);
        }

        $this->assertEquals(ProcessingResult::failed(
            'unsupported_doc_type',
            'Document type is contract-only until runtime ingestion support is implemented.',
        ), $result);
    }

    public function testSupervisorAndEvidenceRetrieverAreNotSilentNoopDocumentProcessors(): void
    {
        $method = new ReflectionMethod(DocumentJobWorkerFactory::class, 'processorFor');
        $executor = new FakeDatabaseExecutor();

        foreach ([WorkerName::Supervisor, WorkerName::EvidenceRetriever] as $workerName) {
            try {
                $method->invoke(null, $workerName, $executor);
                $this->fail($workerName->value . ' should not map to a document job processor.');
            } catch (RuntimeException $e) {
                $this->assertStringContainsString('not a clinical document extraction job processor', $e->getMessage());
            }
        }
    }

    private static function restoreEnv(string $name, string | false $value): void
    {
        if ($value === false) {
            putenv($name);
            return;
        }

        putenv($name . '=' . $value);
    }
}
