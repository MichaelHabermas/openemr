<?php

/**
 * Isolated tests for the procedural upload hook wrapper.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Tests\Isolated\AgentForge\Document;

require_once __DIR__ . '/DocumentUploadEnqueuerTest.php';

use LogicException;
use OpenEMR\AgentForge\Document\CategoryId;
use OpenEMR\AgentForge\Document\DocumentHookServiceBinding;
use OpenEMR\AgentForge\Document\DocumentType;
use OpenEMR\AgentForge\Document\DocumentTypeMapping;
use OpenEMR\AgentForge\Document\DocumentTypeMappingRepository;
use OpenEMR\AgentForge\Document\DocumentUploadEnqueuer;
use OpenEMR\AgentForge\Document\DocumentUploadEnqueuerHook;
use OpenEMR\AgentForge\Observability\PatientRefHasher;
use OpenEMR\BC\ServiceContainer;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use TypeError;

final class DocumentUploadEnqueuerHookTest extends TestCase
{
    protected function tearDown(): void
    {
        DocumentHookServiceBinding::resetForTesting();
        ServiceContainer::reset();
    }

    public function testNonArrayResultReturnsWithoutCallingEnqueuer(): void
    {
        $jobs = new InMemoryDocumentJobRepository();
        $enqueuer = new DocumentUploadEnqueuer(
            InMemoryDocumentTypeMappingRepository::withMapping(7, DocumentType::LabPdf, true),
            $jobs,
            new DocumentRecordingLogger(),
            new PatientRefHasher('test-salt'),
        );
        DocumentHookServiceBinding::setUploadEnqueuerForTesting($enqueuer);

        DocumentUploadEnqueuerHook::dispatch(900001, 7, false);

        $this->assertSame([], $jobs->jobs);
    }

    public function testMissingDocIdReturnsWithoutCallingEnqueuer(): void
    {
        $jobs = new InMemoryDocumentJobRepository();
        $enqueuer = new DocumentUploadEnqueuer(
            InMemoryDocumentTypeMappingRepository::withMapping(7, DocumentType::LabPdf, true),
            $jobs,
            new DocumentRecordingLogger(),
            new PatientRefHasher('test-salt'),
        );
        DocumentHookServiceBinding::setUploadEnqueuerForTesting($enqueuer);

        DocumentUploadEnqueuerHook::dispatch(900001, 7, ['name' => 'lab.pdf']);

        $this->assertSame([], $jobs->jobs);
    }

    public function testValidResultDispatchesValueObjectsToEnqueuer(): void
    {
        $jobs = new InMemoryDocumentJobRepository();
        $logger = new DocumentRecordingLogger();
        $enqueuer = new DocumentUploadEnqueuer(
            InMemoryDocumentTypeMappingRepository::withMapping(7, DocumentType::LabPdf, true),
            $jobs,
            $logger,
            new PatientRefHasher('test-salt'),
        );
        DocumentHookServiceBinding::setUploadEnqueuerForTesting($enqueuer);

        DocumentUploadEnqueuerHook::dispatch(900001, 7, ['doc_id' => 123]);

        $this->assertCount(1, $jobs->jobs);
        $this->assertSame(900001, $jobs->jobs[0]->patientId->value);
        $this->assertSame(123, $jobs->jobs[0]->documentId->value);
    }

    public function testInvalidIdsReturnBeforeEnqueue(): void
    {
        $jobs = new InMemoryDocumentJobRepository();
        $enqueuer = new DocumentUploadEnqueuer(
            InMemoryDocumentTypeMappingRepository::withMapping(7, DocumentType::LabPdf, true),
            $jobs,
            new DocumentRecordingLogger(),
            new PatientRefHasher('test-salt'),
        );
        DocumentHookServiceBinding::setUploadEnqueuerForTesting($enqueuer);

        DocumentUploadEnqueuerHook::dispatch(0, 7, ['doc_id' => 123]);

        $this->assertSame([], $jobs->jobs);
    }

    public function testErrorEscapingEnqueuerIsCaughtAndLoggedByHook(): void
    {
        $logger = new DocumentRecordingLogger();
        $enqueuer = new DocumentUploadEnqueuer(
            new ErrorThrowingDocumentTypeMappingRepository(),
            new InMemoryDocumentJobRepository(),
            $logger,
            new PatientRefHasher('test-salt'),
        );
        DocumentHookServiceBinding::setUploadEnqueuerForTesting($enqueuer);
        ServiceContainer::override(LoggerInterface::class, $logger);

        DocumentUploadEnqueuerHook::dispatch(900001, 7, ['doc_id' => 123]);

        $this->assertCount(1, $logger->records);
        $this->assertSame('error', $logger->records[0]['level']);
        $this->assertSame('clinical_document.job.hook_failed', $logger->records[0]['message']);
        $this->assertSame(\Error::class, $logger->records[0]['context']['error_code']);
        $this->assertArrayNotHasKey('exception', $logger->records[0]['context']);
    }

    public function testEnqueuerTypeErrorIsCaughtAndSanitized(): void
    {
        $logger = new DocumentRecordingLogger();
        $enqueuer = new DocumentUploadEnqueuer(
            new TypeErrorThrowingDocumentTypeMappingRepository(),
            new InMemoryDocumentJobRepository(),
            $logger,
            new PatientRefHasher('test-salt'),
        );
        DocumentHookServiceBinding::setUploadEnqueuerForTesting($enqueuer);
        ServiceContainer::override(LoggerInterface::class, $logger);

        DocumentUploadEnqueuerHook::dispatch(900001, 7, ['doc_id' => 123]);

        $this->assertCount(1, $logger->records);
        $this->assertSame('error', $logger->records[0]['level']);
        $this->assertSame('clinical_document.job.hook_failed', $logger->records[0]['message']);
        $this->assertSame(TypeError::class, $logger->records[0]['context']['error_code']);
        $this->assertArrayNotHasKey('exception', $logger->records[0]['context']);
    }

    public function testExceptionNotAbsorbedByEnqueuerIsCaughtAndLogged(): void
    {
        $logger = new DocumentRecordingLogger();
        $enqueuer = new DocumentUploadEnqueuer(
            new LogicExceptionMappingRepository(),
            new InMemoryDocumentJobRepository(),
            $logger,
            new PatientRefHasher('test-salt'),
        );
        DocumentHookServiceBinding::setUploadEnqueuerForTesting($enqueuer);
        ServiceContainer::override(LoggerInterface::class, $logger);

        DocumentUploadEnqueuerHook::dispatch(900001, 7, ['doc_id' => 123]);

        $this->assertCount(1, $logger->records);
        $this->assertSame('error', $logger->records[0]['level']);
        $this->assertSame('clinical_document.job.hook_failed', $logger->records[0]['message']);
        $this->assertSame(LogicException::class, $logger->records[0]['context']['error_code']);
        $this->assertArrayNotHasKey('exception', $logger->records[0]['context']);
    }
}

final class LogicExceptionMappingRepository implements DocumentTypeMappingRepository
{
    public function findActiveByCategoryId(CategoryId $categoryId): ?DocumentTypeMapping
    {
        throw new LogicException('mapping invariant violated');
    }
}

final class ErrorThrowingDocumentTypeMappingRepository implements DocumentTypeMappingRepository
{
    public function findActiveByCategoryId(CategoryId $categoryId): ?DocumentTypeMapping
    {
        throw new \Error('mapping fatal');
    }
}

final class TypeErrorThrowingDocumentTypeMappingRepository implements DocumentTypeMappingRepository
{
    public function findActiveByCategoryId(CategoryId $categoryId): ?DocumentTypeMapping
    {
        throw new TypeError('typed wiring failed');
    }
}
