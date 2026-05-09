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

    public function testHl7v2JobUsesDeterministicProviderWithoutProviderEnvironment(): void
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
                    docType: DocumentType::Hl7v2Message,
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
                new DocumentLoadResult(
                    "MSH|^~\\&|BHS-LIS|BHS LAB|EHR^^L|EHR|20260506143215||ORU^R01|MSG-WORKER-FACTORY|P|2.5.1\r"
                    . "PID|1||BHS-2847163^^^MRN^MR||CHEN^MARGARET^L||19680312|F\r"
                    . "OBX|1|NM|2089-1^LDL^LN||142|mg/dL|<100|H\r",
                    'text/plain',
                    'message.hl7',
                ),
            );
        } finally {
            self::restoreEnv('AGENTFORGE_VLM_PROVIDER', $openAiProvider);
            self::restoreEnv('AGENTFORGE_OPENAI_API_KEY', $agentForgeOpenAiKey);
            self::restoreEnv('OPENAI_API_KEY', $openAiKey);
        }

        $this->assertEquals(ProcessingResult::failed(
            'identity_ambiguous_needs_review',
            'Patient identity could not be loaded for document verification.',
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
