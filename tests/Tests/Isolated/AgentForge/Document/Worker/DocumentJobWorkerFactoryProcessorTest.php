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

use OpenEMR\AgentForge\Document\Extraction\IntakeExtractorWorker;
use OpenEMR\AgentForge\Document\Worker\DocumentJobWorkerFactory;
use OpenEMR\AgentForge\Document\Worker\NoopDocumentJobProcessor;
use OpenEMR\AgentForge\Document\Worker\WorkerName;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

final class DocumentJobWorkerFactoryProcessorTest extends TestCase
{
    public function testEveryWorkerNameMapsToKnownProcessor(): void
    {
        $method = new ReflectionMethod(DocumentJobWorkerFactory::class, 'processorFor');

        foreach (WorkerName::cases() as $workerName) {
            $processor = $method->invoke(null, $workerName);
            if ($workerName === WorkerName::IntakeExtractor) {
                $this->assertInstanceOf(IntakeExtractorWorker::class, $processor, $workerName->value);
            } else {
                $this->assertInstanceOf(NoopDocumentJobProcessor::class, $processor, $workerName->value);
            }
        }
    }
}
