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

use InvalidArgumentException;
use OpenEMR\AgentForge\Document\DocumentType;
use OpenEMR\AgentForge\Document\DocumentUploadEnqueuer;
use OpenEMR\AgentForge\Document\DocumentUploadEnqueuerHook;
use OpenEMR\AgentForge\Observability\PatientRefHasher;
use OpenEMR\BC\ServiceContainer;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;
use TypeError;

final class DocumentUploadEnqueuerHookTest extends TestCase
{
    protected function tearDown(): void
    {
        ServiceContainer::reset();
    }

    public function testNonArrayResultReturnsWithoutResolvingEnqueuer(): void
    {
        $called = false;

        DocumentUploadEnqueuerHook::dispatch(900001, 7, false, function () use (&$called): DocumentUploadEnqueuer {
            $called = true;
            throw new RuntimeException('should not resolve');
        });

        $this->assertFalse($called);
    }

    public function testMissingDocIdReturnsWithoutResolvingEnqueuer(): void
    {
        $called = false;

        DocumentUploadEnqueuerHook::dispatch(900001, 7, ['name' => 'lab.pdf'], function () use (&$called): DocumentUploadEnqueuer {
            $called = true;
            throw new RuntimeException('should not resolve');
        });

        $this->assertFalse($called);
    }

    public function testValidResultDispatchesValueObjectsToResolvedEnqueuer(): void
    {
        $jobs = new InMemoryDocumentJobRepository();
        $logger = new DocumentRecordingLogger();
        $enqueuer = new DocumentUploadEnqueuer(
            InMemoryDocumentTypeMappingRepository::withMapping(7, DocumentType::LabPdf, true),
            $jobs,
            $logger,
            new PatientRefHasher('test-salt'),
        );

        DocumentUploadEnqueuerHook::dispatch(900001, 7, ['doc_id' => 123], static fn (): DocumentUploadEnqueuer => $enqueuer);

        $this->assertCount(1, $jobs->jobs);
        $this->assertSame(900001, $jobs->jobs[0]->patientId->value);
        $this->assertSame(123, $jobs->jobs[0]->documentId->value);
    }

    public function testInvalidIdsAreCaughtBeforeDispatch(): void
    {
        $jobs = new InMemoryDocumentJobRepository();
        $enqueuer = new DocumentUploadEnqueuer(
            InMemoryDocumentTypeMappingRepository::withMapping(7, DocumentType::LabPdf, true),
            $jobs,
            new DocumentRecordingLogger(),
            new PatientRefHasher('test-salt'),
        );

        DocumentUploadEnqueuerHook::dispatch(0, 7, ['doc_id' => 123], static fn (): DocumentUploadEnqueuer => $enqueuer);

        $this->assertSame([], $jobs->jobs);
    }

    public function testResolverExceptionIsCaught(): void
    {
        $this->expectNotToPerformAssertions();

        DocumentUploadEnqueuerHook::dispatch(900001, 7, ['doc_id' => 123], static function (): DocumentUploadEnqueuer {
            throw new RuntimeException('factory unavailable');
        });
    }

    public function testResolverThrowableIsCaughtAndSanitized(): void
    {
        $logger = new DocumentRecordingLogger();
        ServiceContainer::override(LoggerInterface::class, $logger);

        DocumentUploadEnqueuerHook::dispatch(900001, 7, ['doc_id' => 123], static function (): DocumentUploadEnqueuer {
            throw new TypeError('typed wiring failed');
        });

        $this->assertCount(1, $logger->records);
        $this->assertSame('error', $logger->records[0]['level']);
        $this->assertSame('clinical_document.job.hook_failed', $logger->records[0]['message']);
        $this->assertSame(TypeError::class, $logger->records[0]['context']['error_code']);
        $this->assertArrayNotHasKey('exception', $logger->records[0]['context']);
    }

    public function testResolverValidationExceptionIsCaught(): void
    {
        $this->expectNotToPerformAssertions();

        DocumentUploadEnqueuerHook::dispatch(900001, 7, ['doc_id' => 123], static function (): DocumentUploadEnqueuer {
            throw new InvalidArgumentException('factory returned invalid wiring');
        });
    }

    public function testResolverReturningInvalidServiceIsCaught(): void
    {
        $this->expectNotToPerformAssertions();

        DocumentUploadEnqueuerHook::dispatch(900001, 7, ['doc_id' => 123], static fn (): object => new \stdClass());
    }
}
