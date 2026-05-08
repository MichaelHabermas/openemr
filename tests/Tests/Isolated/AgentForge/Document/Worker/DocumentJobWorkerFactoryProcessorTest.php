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

use OpenEMR\AgentForge\Document\ClinicalDocumentIngestionWorkflow;
use OpenEMR\AgentForge\Document\Worker\DocumentJobWorkerFactory;
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
}
