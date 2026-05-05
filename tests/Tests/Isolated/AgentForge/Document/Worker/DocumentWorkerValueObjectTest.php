<?php

/**
 * Isolated tests for AgentForge document worker value objects.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Tests\Isolated\AgentForge\Document\Worker;

use DomainException;
use InvalidArgumentException;
use OpenEMR\AgentForge\Document\JobStatus;
use OpenEMR\AgentForge\Document\Worker\DocumentLoadException;
use OpenEMR\AgentForge\Document\Worker\DocumentLoadResult;
use OpenEMR\AgentForge\Document\Worker\LockToken;
use OpenEMR\AgentForge\Document\Worker\ProcessingResult;
use OpenEMR\AgentForge\Document\Worker\WorkerName;
use OpenEMR\AgentForge\Document\Worker\WorkerStatus;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class DocumentWorkerValueObjectTest extends TestCase
{
    public function testLockTokenAcceptsGeneratedHexAndExposesPrefix(): void
    {
        $token = new LockToken(str_repeat('a', 64));

        $this->assertSame(str_repeat('a', 64), $token->value);
        $this->assertSame('aaaaaaaa', $token->prefix());
        $this->assertMatchesRegularExpression('/\A[a-f0-9]{64}\z/', LockToken::generate()->value);
    }

    /**
     * @return list<array{string}>
     *
     * @codeCoverageIgnore Data providers run before coverage instrumentation starts.
     */
    public static function invalidLockTokenProvider(): array
    {
        return [
            [''],
            ['ABC'],
            [str_repeat('g', 64)],
            [str_repeat('a', 63)],
            [str_repeat('a', 65)],
        ];
    }

    #[DataProvider('invalidLockTokenProvider')]
    public function testLockTokenRejectsInvalidValues(string $raw): void
    {
        $this->expectException(DomainException::class);

        new LockToken($raw);
    }

    public function testWorkerNameAndStatusParseKnownValues(): void
    {
        $this->assertSame(WorkerName::IntakeExtractor, WorkerName::fromStringOrThrow('intake-extractor'));
        $this->assertSame(WorkerStatus::Running, WorkerStatus::fromStringOrThrow('running'));
    }

    public function testWorkerNameAndStatusRejectUnknownValues(): void
    {
        $this->expectException(DomainException::class);
        WorkerName::fromStringOrThrow('unknown-worker');
    }

    public function testWorkerStatusRejectsUnknownValues(): void
    {
        $this->expectException(DomainException::class);
        WorkerStatus::fromStringOrThrow('unknown-status');
    }

    public function testDocumentLoadResultRequiresBytesAndTracksByteCount(): void
    {
        $result = new DocumentLoadResult('abc', 'text/plain', 'note.txt');

        $this->assertSame('abc', $result->bytes);
        $this->assertSame(3, $result->byteCount);
    }

    public function testDocumentLoadResultRejectsEmptyBytes(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new DocumentLoadResult('', 'text/plain', 'note.txt');
    }

    public function testProcessingResultCapturesTerminalStatus(): void
    {
        $success = ProcessingResult::succeeded();
        $failure = ProcessingResult::failed('processor_failed', 'No-op processor failed.');

        $this->assertSame(JobStatus::Succeeded, $success->terminalStatus);
        $this->assertFalse($success->failedJob());
        $this->assertSame(JobStatus::Failed, $failure->terminalStatus);
        $this->assertTrue($failure->failedJob());
        $this->assertSame('processor_failed', $failure->errorCode);
    }

    public function testProcessingResultFailureRequiresErrorCode(): void
    {
        $this->expectException(InvalidArgumentException::class);

        ProcessingResult::failed('', 'Missing error code.');
    }

    public function testDocumentLoadExceptionFactoriesExposeStableErrorCodes(): void
    {
        $this->assertSame('source_document_missing', DocumentLoadException::missing()->errorCode);
        $this->assertSame('source_document_deleted', DocumentLoadException::sourceDeleted()->errorCode);
        $this->assertSame('source_document_expired', DocumentLoadException::expired()->errorCode);
        $this->assertSame(
            'source_document_unreadable',
            DocumentLoadException::unreadable(new RuntimeException('boom'))->errorCode,
        );
    }
}
