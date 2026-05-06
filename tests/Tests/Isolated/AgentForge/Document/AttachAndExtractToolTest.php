<?php

/**
 * Isolated tests for the M4 attach/extract direct tool surface.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Tests\Isolated\AgentForge\Document;

use OpenEMR\Tests\Isolated\AgentForge\Support\AgentForgeTestFixtures;
use OpenEMR\AgentForge\Auth\PatientId;
use OpenEMR\AgentForge\Deadline;
use OpenEMR\AgentForge\Document\AttachAndExtractTool;
use OpenEMR\AgentForge\Document\DocumentId;
use OpenEMR\AgentForge\Document\DocumentType;
use OpenEMR\AgentForge\Document\Extraction\DocumentExtractionProvider;
use OpenEMR\AgentForge\Document\Extraction\ExtractionProviderException;
use OpenEMR\AgentForge\Document\Extraction\ExtractionProviderResponse;
use OpenEMR\AgentForge\Document\ExtractionErrorCode;
use OpenEMR\AgentForge\Document\Identity\DocumentIdentityVerifier;
use OpenEMR\AgentForge\Document\Identity\ExtractionIdentityEvidenceBuilder;
use OpenEMR\AgentForge\Document\Identity\FixedPatientIdentityRepository;
use OpenEMR\AgentForge\Document\Identity\PatientIdentity;
use OpenEMR\AgentForge\Document\InMemorySourceDocumentStorage;
use OpenEMR\AgentForge\Document\SourceDocumentStorage;
use OpenEMR\AgentForge\Document\Worker\DocumentLoader;
use OpenEMR\AgentForge\Document\Worker\DocumentLoadException;
use OpenEMR\AgentForge\Document\Worker\DocumentLoadResult;
use OpenEMR\AgentForge\ResponseGeneration\DraftUsage;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class AttachAndExtractToolTest extends TestCase
{
    public function testUploadedFileStoresLoadsAndExtracts(): void
    {
        $storage = new InMemorySourceDocumentStorage(50);
        $tool = self::identityGatedTool($storage, $storage, new AttachToolStaticProvider(self::strictResponse()));
        $file = self::tempFile('pdf-bytes');

        $result = $tool->forUploadedFile(new PatientId(1), $file, DocumentType::LabPdf, self::deadline());

        $this->assertTrue($result->success);
        $this->assertSame(50, $result->documentId?->value);
        $this->assertSame(self::strictResponse()->facts, $result->extraction?->facts);
        unlink($file);
    }

    public function testUploadedFileMissingFileFailsBeforeStorage(): void
    {
        $tool = new AttachAndExtractTool(
            new InMemorySourceDocumentStorage(),
            new InMemorySourceDocumentStorage(),
            new AttachToolStaticProvider(self::strictResponse()),
        );

        $result = $tool->forUploadedFile(
            new PatientId(1),
            sys_get_temp_dir() . '/missing-agentforge-source.pdf',
            DocumentType::LabPdf,
            self::deadline(),
        );

        $this->assertFalse($result->success);
        $this->assertSame(ExtractionErrorCode::MissingFile, $result->errorCode);
    }

    public function testStorageFailureReturnsStorageFailure(): void
    {
        $tool = new AttachAndExtractTool(
            new ThrowingSourceDocumentStorage(),
            new InMemorySourceDocumentStorage(),
            new AttachToolStaticProvider(self::strictResponse()),
        );
        $file = self::tempFile('pdf-bytes');

        $result = $tool->forUploadedFile(new PatientId(1), $file, DocumentType::LabPdf, self::deadline());

        $this->assertFalse($result->success);
        $this->assertSame(ExtractionErrorCode::StorageFailure, $result->errorCode);
        unlink($file);
    }

    public function testLoadFailureAfterStoragePreservesDocumentId(): void
    {
        $tool = new AttachAndExtractTool(
            new FixedSourceDocumentStorage(new DocumentId(77)),
            new MissingDocumentLoader(),
            new AttachToolStaticProvider(self::strictResponse()),
        );
        $file = self::tempFile('pdf-bytes');

        $result = $tool->forUploadedFile(new PatientId(1), $file, DocumentType::LabPdf, self::deadline());

        $this->assertFalse($result->success);
        $this->assertSame(ExtractionErrorCode::MissingFile, $result->errorCode);
        $this->assertSame(77, $result->documentId?->value);
        unlink($file);
    }

    public function testExistingDocumentLoadsAndExtracts(): void
    {
        $loader = new FixedDocumentLoader(new DocumentLoadResult('pdf-bytes', 'application/pdf', 'lab.pdf'));
        $tool = self::identityGatedTool(
            new InMemorySourceDocumentStorage(),
            $loader,
            new AttachToolStaticProvider(self::strictResponse()),
        );

        $result = $tool->forExistingDocument(new PatientId(1), new DocumentId(88), DocumentType::LabPdf, self::deadline());

        $this->assertTrue($result->success);
        $this->assertSame(88, $result->documentId?->value);
    }

    public function testExistingDocumentProviderFailurePreservesDocumentId(): void
    {
        $tool = new AttachAndExtractTool(
            new InMemorySourceDocumentStorage(),
            new FixedDocumentLoader(new DocumentLoadResult('pdf-bytes', 'application/pdf', 'lab.pdf')),
            new AttachToolThrowingProvider(new ExtractionProviderException('provider down')),
        );

        $result = $tool->forExistingDocument(new PatientId(1), new DocumentId(88), DocumentType::LabPdf, self::deadline());

        $this->assertFalse($result->success);
        $this->assertSame(ExtractionErrorCode::ExtractionFailure, $result->errorCode);
        $this->assertSame(88, $result->documentId?->value);
    }

    public function testExistingDocumentSchemaInvalidPreservesDocumentId(): void
    {
        $tool = new AttachAndExtractTool(
            new InMemorySourceDocumentStorage(),
            new FixedDocumentLoader(new DocumentLoadResult('pdf-bytes', 'application/pdf', 'lab.pdf')),
            new AttachToolStaticProvider(new ExtractionProviderResponse(false, [], [], DraftUsage::fixture(), 'fixture-vlm')),
        );

        $result = $tool->forExistingDocument(new PatientId(1), new DocumentId(88), DocumentType::LabPdf, self::deadline());

        $this->assertFalse($result->success);
        $this->assertSame(ExtractionErrorCode::SchemaValidationFailure, $result->errorCode);
        $this->assertSame(88, $result->documentId?->value);
    }

    private static function strictResponse(): ExtractionProviderResponse
    {
        return ExtractionProviderResponse::fromStrictJson(
            DocumentType::LabPdf,
            json_encode([
                'doc_type' => 'lab_pdf',
                'lab_name' => 'Acme Lab',
                'collected_at' => '2026-05-01',
                'patient_identity' => self::identityCandidates(),
                'results' => [[
                    'test_name' => 'Potassium',
                    'value' => '5.4',
                    'unit' => 'mmol/L',
                    'reference_range' => '3.5-5.1',
                    'collected_at' => '2026-05-01',
                    'abnormal_flag' => 'high',
                    'certainty' => 'verified',
                    'confidence' => 0.91,
                    'citation' => [
                        'source_type' => 'lab_pdf',
                        'source_id' => 'documents:50',
                        'page_or_section' => 'page 1',
                        'field_or_chunk_id' => 'results[0]',
                        'quote_or_value' => 'Potassium 5.4 H',
                        'bounding_box' => ['x' => 0.1, 'y' => 0.1, 'width' => 0.2, 'height' => 0.1],
                    ],
                ]],
            ], JSON_THROW_ON_ERROR),
            DraftUsage::fixture(),
            'fixture-vlm',
        );
    }

    private static function identityGatedTool(
        SourceDocumentStorage $storage,
        DocumentLoader $loader,
        DocumentExtractionProvider $provider,
    ): AttachAndExtractTool {
        return new AttachAndExtractTool(
            $storage,
            $loader,
            $provider,
            new FixedPatientIdentityRepository(new PatientIdentity(new PatientId(1), 'Jane', 'Doe', '1980-04-15')),
            new DocumentIdentityVerifier(),
            new ExtractionIdentityEvidenceBuilder(),
        );
    }

    /** @return list<array<string, mixed>> */
    private static function identityCandidates(): array
    {
        return [
            [
                'kind' => 'patient_name',
                'value' => 'Jane Doe',
                'field_path' => 'patient_identity[0]',
                'certainty' => 'verified',
                'confidence' => 0.99,
                'citation' => [
                    'source_type' => 'lab_pdf',
                    'source_id' => 'documents:50',
                    'page_or_section' => 'page 1',
                    'field_or_chunk_id' => 'patient_name',
                    'quote_or_value' => 'Jane Doe',
                ],
            ],
            [
                'kind' => 'date_of_birth',
                'value' => '1980-04-15',
                'field_path' => 'patient_identity[1]',
                'certainty' => 'verified',
                'confidence' => 0.99,
                'citation' => [
                    'source_type' => 'lab_pdf',
                    'source_id' => 'documents:50',
                    'page_or_section' => 'page 1',
                    'field_or_chunk_id' => 'date_of_birth',
                    'quote_or_value' => '1980-04-15',
                ],
            ],
        ];
    }

    private static function deadline(): Deadline
    {
        return new Deadline(AgentForgeTestFixtures::frozenMonotonicClock(1_000), 30_000);
    }

    private static function tempFile(string $bytes): string
    {
        $file = tempnam(sys_get_temp_dir(), 'agentforge-tool-');
        TestCase::assertIsString($file);
        file_put_contents($file, $bytes);

        return $file;
    }
}

final readonly class FixedSourceDocumentStorage implements SourceDocumentStorage
{
    public function __construct(private DocumentId $documentId)
    {
    }

    public function storeUploadedFile(PatientId $patientId, string $filePath, DocumentType $docType): DocumentId
    {
        return $this->documentId;
    }
}

final class ThrowingSourceDocumentStorage implements SourceDocumentStorage
{
    public function storeUploadedFile(PatientId $patientId, string $filePath, DocumentType $docType): DocumentId
    {
        throw new RuntimeException('storage failed');
    }
}

final readonly class FixedDocumentLoader implements DocumentLoader
{
    public function __construct(private DocumentLoadResult $result)
    {
    }

    public function load(DocumentId $documentId): DocumentLoadResult
    {
        return $this->result;
    }
}

final class MissingDocumentLoader implements DocumentLoader
{
    public function load(DocumentId $documentId): DocumentLoadResult
    {
        throw DocumentLoadException::missing();
    }
}

final readonly class AttachToolStaticProvider implements DocumentExtractionProvider
{
    public function __construct(private ExtractionProviderResponse $response)
    {
    }

    public function extract(
        DocumentId $documentId,
        DocumentLoadResult $document,
        DocumentType $documentType,
        Deadline $deadline,
    ): ExtractionProviderResponse {
        return $this->response;
    }
}

final readonly class AttachToolThrowingProvider implements DocumentExtractionProvider
{
    public function __construct(private ExtractionProviderException $exception)
    {
    }

    public function extract(
        DocumentId $documentId,
        DocumentLoadResult $document,
        DocumentType $documentType,
        Deadline $deadline,
    ): ExtractionProviderResponse {
        throw $this->exception;
    }
}
