<?php

/**
 * Isolated tests for AgentForge OpenAI VLM extraction provider.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Tests\Isolated\AgentForge\Document\Extraction;

use BadMethodCallException;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Psr7\Response;
use OpenEMR\AgentForge\Deadline;
use OpenEMR\AgentForge\Document\Content\DocumentContentNormalizationRequest;
use OpenEMR\AgentForge\Document\Content\DocumentContentNormalizer;
use OpenEMR\AgentForge\Document\Content\DocumentContentNormalizerRegistry;
use OpenEMR\AgentForge\Document\Content\NormalizedDocumentContent;
use OpenEMR\AgentForge\Document\Content\NormalizedDocumentSource;
use OpenEMR\AgentForge\Document\Content\NormalizedMessageSegment;
use OpenEMR\AgentForge\Document\Content\NormalizedRenderedPage;
use OpenEMR\AgentForge\Document\Content\NormalizedTable;
use OpenEMR\AgentForge\Document\Content\NormalizedTextSection;
use OpenEMR\AgentForge\Document\DocumentId;
use OpenEMR\AgentForge\Document\DocumentType;
use OpenEMR\AgentForge\Document\Extraction\ExtractionProviderException;
use OpenEMR\AgentForge\Document\Extraction\OpenAiVlmExtractionProvider;
use OpenEMR\AgentForge\Document\Extraction\PdfPageRenderer;
use OpenEMR\AgentForge\Document\Extraction\RenderedPdfPage;
use OpenEMR\AgentForge\Document\ExtractionErrorCode;
use OpenEMR\AgentForge\Document\Worker\DocumentLoadResult;
use OpenEMR\AgentForge\Time\SystemMonotonicClock;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

final class OpenAiVlmExtractionProviderTest extends TestCase
{
    public function testExtractSendsStrictJsonSchemaPayloadAndParsesResponse(): void
    {
        $client = new RecordingExtractionOpenAiClient($this->openAiResponse());
        $renderer = new TestPdfPageRenderer();
        $provider = new OpenAiVlmExtractionProvider(
            $client,
            'test-key',
            'gpt-4o-mini',
            $renderer,
            inputCostPerMillionTokens: 0.15,
            outputCostPerMillionTokens: 0.60,
            maxPdfPages: 2,
        );

        $response = $provider->extract(
            new DocumentId(123),
            new DocumentLoadResult('%PDF fixture', 'application/pdf', 'lab.pdf'),
            DocumentType::LabPdf,
            $this->deadline(),
        );

        $this->assertTrue($response->schemaValid);
        $this->assertSame('lab_result', $response->facts[0]['type']);
        $this->assertSame('LDL', $response->facts[0]['label']);
        $this->assertSame('verified', $response->facts[0]['certainty']);
        $this->assertSame('gpt-4o-mini', $response->usage->model);
        $this->assertSame(120, $response->usage->inputTokens);
        $this->assertSame(30, $response->usage->outputTokens);
        $this->assertSame(0.000036, $response->usage->estimatedCost);
        $this->assertSame('pdf', $response->normalizationTelemetry['normalizer'] ?? null);
        $this->assertSame('application/pdf', $response->normalizationTelemetry['source_mime_type'] ?? null);
        $this->assertSame(strlen('%PDF fixture'), $response->normalizationTelemetry['source_byte_count'] ?? null);
        $this->assertSame(1, $response->normalizationTelemetry['rendered_page_count'] ?? null);
        $this->assertSame('%PDF fixture', $renderer->lastPdfBytes);
        $this->assertSame(2, $renderer->lastMaxPages);

        $payload = $client->lastPayload();
        $this->assertSame('gpt-4o-mini', $payload['model']);
        $this->assertSame(0, $payload['temperature']);
        $this->assertSame('json_schema', $this->stringPath($payload, ['response_format', 'type']));
        $this->assertTrue($this->boolPath($payload, ['response_format', 'json_schema', 'strict']));
        $this->assertSame('agentforge_document_extraction', $this->stringPath($payload, ['response_format', 'json_schema', 'name']));
        $this->assertSame(
            ['doc_type', 'lab_name', 'collected_at', 'patient_identity', 'results'],
            $this->arrayPath($payload, ['response_format', 'json_schema', 'schema', 'required']),
        );
        $this->assertStringNotContainsString(
            'lab.pdf',
            json_encode($payload, JSON_THROW_ON_ERROR),
        );
        $this->assertStringContainsString(
            'Requested document type: lab_pdf',
            $this->stringPath($payload, ['messages', 0, 'content']),
        );

        $content = $this->arrayPath($payload, ['messages', 1, 'content']);
        $this->assertIsArray($content[0] ?? null);
        $this->assertIsArray($content[1] ?? null);
        $this->assertSame('text', $content[0]['type']);
        $this->assertSame('image_url', $content[1]['type']);
        $imageUrl = $content[1]['image_url'] ?? null;
        $this->assertIsArray($imageUrl);
        $this->assertSame('data:image/png;base64,cGFnZS0x', $imageUrl['url']);
    }

    public function testExtractIntakeFormWithImagePngUsesSchemaAndDataUrlImage(): void
    {
        $client = new RecordingExtractionOpenAiClient($this->openAiIntakeResponse());
        $provider = new OpenAiVlmExtractionProvider(
            $client,
            'test-key',
            'gpt-4o-mini',
            new TestPdfPageRenderer(),
        );

        $pngOnePixel = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==', true);
        $this->assertNotFalse($pngOnePixel);

        $response = $provider->extract(
            new DocumentId(7),
            new DocumentLoadResult($pngOnePixel, 'image/png', 'scan.png'),
            DocumentType::IntakeForm,
            $this->deadline(),
        );

        $this->assertTrue($response->schemaValid);
        $this->assertSame('intake_finding', $response->facts[0]['type'] ?? null);

        $payload = $client->lastPayload();
        $this->assertSame(
            ['doc_type', 'form_name', 'patient_identity', 'findings'],
            $this->arrayPath($payload, ['response_format', 'json_schema', 'schema', 'required']),
        );
        $content = $this->arrayPath($payload, ['messages', 1, 'content']);
        $this->assertSecondContentImagePngDataUrl($content);
    }

    public function testExtractIntakeFormWithImageJpegUsesDataUrlImage(): void
    {
        $client = new RecordingExtractionOpenAiClient($this->openAiIntakeResponse());
        $provider = new OpenAiVlmExtractionProvider(
            $client,
            'test-key',
            'gpt-4o-mini',
            new TestPdfPageRenderer(),
        );

        $jpegMinimal = base64_decode('/9j/4AAQSkZJRgABAQEASABIAAD/2wBDAAgGBgcGBQgHBwcJCQgKDBQNDAsLDBkSEw8UHRofHh0aHBwgJC4nICIsIxwcKDcpLDAxNDQ0Hyc5PTgyPC4zNDL/2wBDAQkJCQwLDBgNDRgyIRwhMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjL/wAARCAABAAEDASIAAhEBAxEB/8QAFQABAQAAAAAAAAAAAAAAAAAAAAX/xAAUEAEAAAAAAAAAAAAAAAAAAAAA/8QAFQEBAQAAAAAAAAAAAAAAAAAAAAX/xAAUEQEAAAAAAAAAAAAAAAAAAAAA/9oADAMBAAIRAxEAPwCwAA8A/9k=', true);
        $this->assertNotFalse($jpegMinimal);

        $provider->extract(
            new DocumentId(8),
            new DocumentLoadResult($jpegMinimal, 'image/jpeg', 'photo.jpg'),
            DocumentType::IntakeForm,
            $this->deadline(),
        );

        $payload = $client->lastPayload();
        $content = $this->arrayPath($payload, ['messages', 1, 'content']);
        $this->assertSecondContentImageJpegDataUrl($content);
    }

    public function testExtractFaxPacketUsesFaxSchemaAndRenderedTiffPages(): void
    {
        $client = new RecordingExtractionOpenAiClient($this->openAiFaxResponse());
        $provider = new OpenAiVlmExtractionProvider(
            $client,
            'test-key',
            'gpt-4o-mini',
            new TestPdfPageRenderer(),
            contentNormalizers: new DocumentContentNormalizerRegistry([
                new OpenAiProviderRenderedFaxNormalizer(),
            ]),
        );

        $response = $provider->extract(
            new DocumentId(13),
            new DocumentLoadResult('raw-tiff-bytes', 'image/tiff', 'chen-fax-packet.tiff'),
            DocumentType::FaxPacket,
            $this->deadline(),
        );

        $this->assertTrue($response->schemaValid);
        $this->assertSame('fax_packet', $this->factCitationSourceType($response->facts[0] ?? []));
        $payload = $client->lastPayload();
        $this->assertSame(
            ['doc_type', 'packet_name', 'patient_identity', 'facts'],
            $this->arrayPath($payload, ['response_format', 'json_schema', 'schema', 'required']),
        );
        $this->assertStringContainsString(
            'Requested document type: fax_packet',
            $this->stringPath($payload, ['messages', 0, 'content']),
        );
        $encodedPayload = json_encode($payload, JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString('chen-fax-packet.tiff', $encodedPayload);
        $this->assertStringNotContainsString('raw-tiff-bytes', $encodedPayload);

        $content = $this->arrayPath($payload, ['messages', 1, 'content']);
        $this->assertCount(3, $content);
        $this->assertSame('text', $this->contentBlockType($content, 0));
        $this->assertSame('image_url', $this->contentBlockType($content, 1));
        $this->assertSame('data:image/png;base64,dGlmZi1wYWdlLTE=', $this->imageDataUrlAt($content, 1));
        $this->assertSame('image_url', $this->contentBlockType($content, 2));
        $this->assertSame('data:image/png;base64,dGlmZi1wYWdlLTI=', $this->imageDataUrlAt($content, 2));
        $this->assertSame('tiff', $response->normalizationTelemetry['normalizer'] ?? null);
        $this->assertSame(2, $response->normalizationTelemetry['rendered_page_count'] ?? null);
    }

    public function testExtractReferralDocxUsesReferralSchemaAndNormalizedTextTables(): void
    {
        $client = new RecordingExtractionOpenAiClient($this->openAiReferralResponse());
        $provider = new OpenAiVlmExtractionProvider(
            $client,
            'test-key',
            'gpt-4o-mini',
            new TestPdfPageRenderer(),
            contentNormalizers: new DocumentContentNormalizerRegistry([
                new OpenAiProviderReferralDocxNormalizer(),
            ]),
        );

        $response = $provider->extract(
            new DocumentId(16),
            new DocumentLoadResult('docx-source-bytes', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'chen-referral.docx'),
            DocumentType::ReferralDocx,
            $this->deadline(),
        );

        $this->assertTrue($response->schemaValid);
        $this->assertSame('referral_docx', $this->factCitationSourceType($response->facts[0] ?? []));
        $payload = $client->lastPayload();
        $this->assertSame(
            ['doc_type', 'referral_name', 'patient_identity', 'facts'],
            $this->arrayPath($payload, ['response_format', 'json_schema', 'schema', 'required']),
        );
        $this->assertStringContainsString(
            'Requested document type: referral_docx',
            $this->stringPath($payload, ['messages', 0, 'content']),
        );
        $encodedPayload = json_encode($payload, JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString('chen-referral.docx', $encodedPayload);
        $this->assertStringNotContainsString('docx-source-bytes', $encodedPayload);

        $content = $this->arrayPath($payload, ['messages', 1, 'content']);
        $this->assertCount(3, $content);
        $this->assertStringContainsString('Normalized text section paragraph:3', $this->contentTextAt($content, 1));
        $this->assertStringContainsString('section:reason-for-referral; paragraph:3', $this->contentTextAt($content, 1));
        $this->assertStringContainsString('Cardiology consult requested', $this->contentTextAt($content, 1));
        $this->assertStringContainsString('Normalized table table:1', $this->contentTextAt($content, 2));
        $this->assertStringContainsString('table:1.row:1', $this->contentTextAt($content, 2));
        $this->assertSame('docx', $response->normalizationTelemetry['normalizer'] ?? null);
        $this->assertSame(1, $response->normalizationTelemetry['text_section_count'] ?? null);
        $this->assertSame(1, $response->normalizationTelemetry['table_count'] ?? null);
    }

    public function testExtractReferralDocxWithDefaultRegistryNormalizesRealFixture(): void
    {
        $bytes = file_get_contents(__DIR__ . '/../../../../../../agent-forge/docs/example-documents/docx/p01-chen-referral.docx');
        $this->assertIsString($bytes);
        $client = new RecordingExtractionOpenAiClient($this->openAiReferralResponse());
        $provider = new OpenAiVlmExtractionProvider(
            $client,
            'test-key',
            'gpt-4o-mini',
            new TestPdfPageRenderer(),
        );

        $response = $provider->extract(
            new DocumentId(17),
            new DocumentLoadResult($bytes, 'application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'p01-chen-referral.docx'),
            DocumentType::ReferralDocx,
            $this->deadline(),
        );

        $this->assertTrue($response->schemaValid);
        $this->assertSame('docx', $response->normalizationTelemetry['normalizer'] ?? null);
        $this->assertGreaterThan(0, $response->normalizationTelemetry['text_section_count'] ?? 0);

        $payloadJson = json_encode($client->lastPayload(), JSON_THROW_ON_ERROR);
        $this->assertStringContainsString('section:reason-for-referral', $payloadJson);
        $this->assertStringContainsString('statin-refractory hyperlipidemia', $payloadJson);
        $this->assertStringNotContainsString('p01-chen-referral.docx', $payloadJson);
    }

    public function testExtractClinicalWorkbookUsesWorkbookSchemaAndNormalizedTables(): void
    {
        $client = new RecordingExtractionOpenAiClient($this->openAiWorkbookResponse());
        $provider = new OpenAiVlmExtractionProvider(
            $client,
            'test-key',
            'gpt-4o-mini',
            new TestPdfPageRenderer(),
            contentNormalizers: new DocumentContentNormalizerRegistry([
                new OpenAiProviderWorkbookNormalizer(),
            ]),
        );

        $response = $provider->extract(
            new DocumentId(18),
            new DocumentLoadResult('xlsx-source-bytes', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'chen-workbook.xlsx'),
            DocumentType::ClinicalWorkbook,
            $this->deadline(),
        );

        $this->assertTrue($response->schemaValid);
        $this->assertSame('clinical_workbook', $this->factCitationSourceType($response->facts[0] ?? []));
        $payload = $client->lastPayload();
        $this->assertSame(
            ['doc_type', 'workbook_name', 'patient_identity', 'facts'],
            $this->arrayPath($payload, ['response_format', 'json_schema', 'schema', 'required']),
        );
        $this->assertStringContainsString(
            'Requested document type: clinical_workbook',
            $this->stringPath($payload, ['messages', 0, 'content']),
        );
        $encodedPayload = json_encode($payload, JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString('chen-workbook.xlsx', $encodedPayload);
        $this->assertStringNotContainsString('xlsx-source-bytes', $encodedPayload);

        $content = $this->arrayPath($payload, ['messages', 1, 'content']);
        $this->assertCount(3, $content);
        $this->assertStringContainsString('Normalized text section Patient!B2', $this->contentTextAt($content, 1));
        $this->assertStringContainsString('sheet:Patient; Patient!B2', $this->contentTextAt($content, 1));
        $this->assertStringContainsString('Normalized table sheet:Labs_Trend', $this->contentTextAt($content, 2));
        $this->assertStringContainsString('Labs_Trend!H3', $this->contentTextAt($content, 2));
        $this->assertSame('xlsx', $response->normalizationTelemetry['normalizer'] ?? null);
        $this->assertSame(1, $response->normalizationTelemetry['text_section_count'] ?? null);
        $this->assertSame(1, $response->normalizationTelemetry['table_count'] ?? null);
    }

    public function testExtractClinicalWorkbookWithDefaultRegistryNormalizesRealFixture(): void
    {
        $bytes = file_get_contents(__DIR__ . '/../../../../../../agent-forge/docs/example-documents/xlsx/p01-chen-workbook.xlsx');
        $this->assertIsString($bytes);
        $client = new RecordingExtractionOpenAiClient($this->openAiWorkbookResponse());
        $provider = new OpenAiVlmExtractionProvider(
            $client,
            'test-key',
            'gpt-4o-mini',
            new TestPdfPageRenderer(),
        );

        $response = $provider->extract(
            new DocumentId(19),
            new DocumentLoadResult($bytes, 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'p01-chen-workbook.xlsx'),
            DocumentType::ClinicalWorkbook,
            $this->deadline(),
        );

        $this->assertTrue($response->schemaValid);
        $this->assertSame('xlsx', $response->normalizationTelemetry['normalizer'] ?? null);
        $this->assertGreaterThan(0, $response->normalizationTelemetry['table_count'] ?? 0);

        $payloadJson = json_encode($client->lastPayload(), JSON_THROW_ON_ERROR);
        $this->assertStringContainsString('Patient!B2', $payloadJson);
        $this->assertStringContainsString('Labs_Trend!H3', $payloadJson);
        $this->assertStringContainsString('Care_Gaps!A4:F4', $payloadJson);
        $this->assertStringNotContainsString('p01-chen-workbook.xlsx', $payloadJson);
    }


    public function testUnsupportedMimeFailsWithStableNormalizationError(): void
    {
        $provider = new OpenAiVlmExtractionProvider(
            new RecordingExtractionOpenAiClient($this->openAiResponse()),
            'test-key',
            'gpt-4o-mini',
            new TestPdfPageRenderer(),
        );

        try {
            $provider->extract(
                new DocumentId(9),
                new DocumentLoadResult('plain text contents', 'text/plain', 'note.txt'),
                DocumentType::LabPdf,
                $this->deadline(),
            );
            $this->fail('Expected unsupported MIME type to fail before OpenAI request.');
        } catch (ExtractionProviderException $exception) {
            $this->assertSame(ExtractionErrorCode::UnsupportedMimeType, $exception->errorCode);
            $this->assertStringContainsString('text/plain', $exception->getMessage());
            $this->assertStringNotContainsString('plain text contents', $exception->getMessage());
            $this->assertStringNotContainsString('note.txt', $exception->getMessage());
        }
    }

    public function testTiffMimeFailsForNonFaxDocumentType(): void
    {
        $provider = new OpenAiVlmExtractionProvider(
            new RecordingExtractionOpenAiClient($this->openAiResponse()),
            'test-key',
            'gpt-4o-mini',
            new TestPdfPageRenderer(),
        );

        try {
            $provider->extract(
                new DocumentId(14),
                new DocumentLoadResult('raw-tiff-bytes', 'image/tiff', 'lab.tiff'),
                DocumentType::LabPdf,
                $this->deadline(),
            );
            $this->fail('Expected TIFF MIME to fail for non-fax document type.');
        } catch (ExtractionProviderException $exception) {
            $this->assertSame(ExtractionErrorCode::UnsupportedMimeType, $exception->errorCode);
            $this->assertStringContainsString('image/tiff', $exception->getMessage());
            $this->assertStringNotContainsString('raw-tiff-bytes', $exception->getMessage());
            $this->assertStringNotContainsString('lab.tiff', $exception->getMessage());
        }
    }

    public function testFaxPacketRejectsPdfAndSingleImageMimeTypes(): void
    {
        $provider = new OpenAiVlmExtractionProvider(
            new RecordingExtractionOpenAiClient($this->openAiFaxResponse()),
            'test-key',
            'gpt-4o-mini',
            new TestPdfPageRenderer(),
        );

        foreach ([
            ['application/pdf', '%PDF fax bytes', 'fax.pdf'],
            ['image/png', 'png fax bytes', 'fax.png'],
        ] as [$mimeType, $bytes, $name]) {
            try {
                $provider->extract(
                    new DocumentId(15),
                    new DocumentLoadResult($bytes, $mimeType, $name),
                    DocumentType::FaxPacket,
                    $this->deadline(),
                );
                $this->fail('Expected non-TIFF fax packet content to fail before OpenAI request.');
            } catch (ExtractionProviderException $exception) {
                $this->assertSame(ExtractionErrorCode::UnsupportedMimeType, $exception->errorCode);
                $this->assertStringContainsString($mimeType, $exception->getMessage());
                $this->assertStringNotContainsString($bytes, $exception->getMessage());
                $this->assertStringNotContainsString($name, $exception->getMessage());
            }
        }
    }

    public function testNormalizedTextTableAndMessageSegmentsAreSentToOpenAi(): void
    {
        $client = new RecordingExtractionOpenAiClient($this->openAiIntakeResponse());
        $provider = new OpenAiVlmExtractionProvider(
            $client,
            'test-key',
            'gpt-4o-mini',
            new TestPdfPageRenderer(),
            contentNormalizers: new DocumentContentNormalizerRegistry([
                new OpenAiProviderTextContentNormalizer(),
            ]),
        );

        $provider->extract(
            new DocumentId(10),
            new DocumentLoadResult('normalized-source', 'application/test-normalized', 'normalized.source'),
            DocumentType::IntakeForm,
            $this->deadline(),
        );

        $content = $this->arrayPath($client->lastPayload(), ['messages', 1, 'content']);
        $this->assertCount(4, $content);
        $this->assertStringContainsString('Normalized text section section-1', $this->contentTextAt($content, 1));
        $this->assertStringContainsString('Current medications: aspirin', $this->contentTextAt($content, 1));
        $this->assertStringContainsString('Normalized table table-1', $this->contentTextAt($content, 2));
        $this->assertStringContainsString('"Medication"', $this->contentTextAt($content, 2));
        $this->assertStringContainsString('Normalized message segment segment-1', $this->contentTextAt($content, 3));
        $this->assertStringContainsString('"PID.5":"Jane Doe"', $this->contentTextAt($content, 3));
    }

    /**
     * @param array<int|string, mixed> $content
     */
    private function assertSecondContentImagePngDataUrl(array $content): void
    {
        $url = $this->secondContentBlockImageDataUrl($content);
        $this->assertStringStartsWith('data:image/png;base64,', $url);
    }

    /**
     * @param array<int|string, mixed> $content
     */
    private function assertSecondContentImageJpegDataUrl(array $content): void
    {
        $url = $this->secondContentBlockImageDataUrl($content);
        $this->assertStringStartsWith('data:image/jpeg;base64,', $url);
    }

    /**
     * @param array<int|string, mixed> $content
     */
    private function secondContentBlockImageDataUrl(array $content): string
    {
        $second = $content[1] ?? null;
        if (!is_array($second)) {
            $this->fail('Expected messages[1].content[1] to be an array.');
        }

        $this->assertSame('image_url', $second['type'] ?? null);
        $imageUrl = $second['image_url'] ?? null;
        if (!is_array($imageUrl)) {
            $this->fail('Expected image_url payload to be an array.');
        }

        $url = $imageUrl['url'] ?? null;
        if (!is_string($url)) {
            $this->fail('Expected image_url.url to be a string.');
        }

        return $url;
    }

    /**
     * @param array<int|string, mixed> $fact
     */
    private function factCitationSourceType(array $fact): string
    {
        $citation = $fact['citation'] ?? null;
        if (!is_array($citation)) {
            $this->fail('Expected fact citation to be an array.');
        }
        $sourceType = $citation['source_type'] ?? null;
        if (!is_string($sourceType)) {
            $this->fail('Expected citation source_type to be a string.');
        }

        return $sourceType;
    }

    /**
     * @param array<int|string, mixed> $content
     */
    private function contentBlockType(array $content, int $index): string
    {
        $block = $this->contentBlockAt($content, $index);
        $type = $block['type'] ?? null;
        if (!is_string($type)) {
            $this->fail('Expected content block type to be a string.');
        }

        return $type;
    }

    /**
     * @param array<int|string, mixed> $content
     */
    private function imageDataUrlAt(array $content, int $index): string
    {
        $block = $this->contentBlockAt($content, $index);
        $imageUrl = $block['image_url'] ?? null;
        if (!is_array($imageUrl)) {
            $this->fail('Expected content block image_url to be an array.');
        }
        $url = $imageUrl['url'] ?? null;
        if (!is_string($url)) {
            $this->fail('Expected image_url.url to be a string.');
        }

        return $url;
    }

    /**
     * @param array<int|string, mixed> $content
     * @return array<string, mixed>
     */
    private function contentBlockAt(array $content, int $index): array
    {
        $block = $content[$index] ?? null;
        if (!is_array($block)) {
            $this->fail('Expected content block to be an array.');
        }

        return $this->stringKeyedArray($block);
    }

    /**
     * @param array<mixed> $source
     * @return array<string, mixed>
     */
    private function stringKeyedArray(array $source): array
    {
        $out = [];
        foreach ($source as $key => $value) {
            if (!is_string($key)) {
                $this->fail('Expected string-keyed array.');
            }
            $out[$key] = $value;
        }

        return $out;
    }

    /** @param array<int|string, mixed> $content */
    private function contentTextAt(array $content, int $index): string
    {
        $block = $content[$index] ?? null;
        if (!is_array($block)) {
            $this->fail('Expected content block to be an array.');
        }
        $this->assertSame('text', $block['type'] ?? null);
        $text = $block['text'] ?? null;
        if (!is_string($text)) {
            $this->fail('Expected content block text to be a string.');
        }

        return $text;
    }

    /** @return array<string, mixed> */
    private function openAiResponse(): array
    {
        return [
            'choices' => [
                [
                    'message' => [
                        'content' => json_encode([
                            'doc_type' => 'lab_pdf',
                            'lab_name' => 'Acme Lab',
                            'collected_at' => '2026-04-01',
                            'patient_identity' => [],
                            'results' => [
                                [
                                    'test_name' => 'LDL',
                                    'value' => '91 mg/dL',
                                    'unit' => 'mg/dL',
                                    'reference_range' => '<100 mg/dL',
                                    'collected_at' => '2026-04-01',
                                    'abnormal_flag' => 'normal',
                                    'certainty' => 'verified',
                                    'confidence' => 0.97,
                                    'citation' => [
                                        'source_type' => 'lab_pdf',
                                        'source_id' => 'sha256:fixture',
                                        'page_or_section' => 'page 1',
                                        'field_or_chunk_id' => 'results[0]',
                                        'quote_or_value' => 'LDL 91 mg/dL',
                                        'bounding_box' => ['x' => 0.1, 'y' => 0.2, 'width' => 0.3, 'height' => 0.4],
                                    ],
                                ],
                            ],
                        ], JSON_THROW_ON_ERROR),
                    ],
                ],
            ],
            'usage' => ['prompt_tokens' => 120, 'completion_tokens' => 30],
        ];
    }

    /** @return array<string, mixed> */
    private function openAiIntakeResponse(): array
    {
        return [
            'choices' => [
                [
                    'message' => [
                        'content' => json_encode([
                            'doc_type' => 'intake_form',
                            'form_name' => 'Patient Intake',
                            'patient_identity' => [],
                            'findings' => [
                                [
                                    'field' => 'Chief complaint',
                                    'value' => 'Headache',
                                    'certainty' => 'document_fact',
                                    'confidence' => 0.88,
                                    'citation' => [
                                        'source_type' => 'intake_form',
                                        'source_id' => 'sha256:fixture',
                                        'page_or_section' => 'page 1',
                                        'field_or_chunk_id' => 'cc',
                                        'quote_or_value' => 'Headache',
                                    ],
                                ],
                            ],
                        ], JSON_THROW_ON_ERROR),
                    ],
                ],
            ],
            'usage' => ['prompt_tokens' => 50, 'completion_tokens' => 20],
        ];
    }

    /** @return array<string, mixed> */
    private function openAiFaxResponse(): array
    {
        return [
            'choices' => [
                [
                    'message' => [
                        'content' => json_encode([
                            'doc_type' => 'fax_packet',
                            'packet_name' => 'Fax packet',
                            'patient_identity' => [],
                            'facts' => [
                                [
                                    'type' => 'fax_note',
                                    'field_path' => 'facts[0]',
                                    'label' => 'Referral reason',
                                    'value' => 'Cardiology consult',
                                    'certainty' => 'document_fact',
                                    'confidence' => 0.86,
                                    'citation' => [
                                        'source_type' => 'fax_packet',
                                        'source_id' => 'sha256:fixture',
                                        'page_or_section' => 'page 2',
                                        'field_or_chunk_id' => 'facts[0]',
                                        'quote_or_value' => 'Cardiology consult',
                                        'bounding_box' => ['x' => 0.1, 'y' => 0.2, 'width' => 0.3, 'height' => 0.4],
                                    ],
                                ],
                            ],
                        ], JSON_THROW_ON_ERROR),
                    ],
                ],
            ],
            'usage' => ['prompt_tokens' => 70, 'completion_tokens' => 25],
        ];
    }

    /** @return array<string, mixed> */
    private function openAiReferralResponse(): array
    {
        return [
            'choices' => [
                [
                    'message' => [
                        'content' => json_encode([
                            'doc_type' => 'referral_docx',
                            'referral_name' => 'Referral letter',
                            'patient_identity' => [],
                            'facts' => [
                                [
                                    'type' => 'referral_reason',
                                    'field_path' => 'reason_for_referral',
                                    'label' => 'Reason for Referral',
                                    'value' => 'Cardiology consult',
                                    'certainty' => 'document_fact',
                                    'confidence' => 0.86,
                                    'citation' => [
                                        'source_type' => 'referral_docx',
                                        'source_id' => 'sha256:fixture',
                                        'page_or_section' => 'section:reason-for-referral',
                                        'field_or_chunk_id' => 'paragraph:3',
                                        'quote_or_value' => 'Cardiology consult',
                                    ],
                                ],
                            ],
                        ], JSON_THROW_ON_ERROR),
                    ],
                ],
            ],
            'usage' => ['prompt_tokens' => 70, 'completion_tokens' => 25],
        ];
    }

    /** @return array<string, mixed> */
    private function openAiWorkbookResponse(): array
    {
        return [
            'choices' => [
                [
                    'message' => [
                        'content' => json_encode([
                            'doc_type' => 'clinical_workbook',
                            'workbook_name' => 'Clinical workbook',
                            'patient_identity' => [],
                            'facts' => [
                                [
                                    'type' => 'lab_result',
                                    'field_path' => 'Labs_Trend!H3',
                                    'label' => 'LDL cholesterol (calc)',
                                    'value' => '142 mg/dL on 2026-04-12',
                                    'certainty' => 'document_fact',
                                    'confidence' => 0.98,
                                    'citation' => [
                                        'source_type' => 'clinical_workbook',
                                        'source_id' => 'sha256:fixture',
                                        'page_or_section' => 'sheet:Labs_Trend',
                                        'field_or_chunk_id' => 'H3',
                                        'quote_or_value' => 'LDL cholesterol (calc) 142 mg/dL on 2026-04-12',
                                    ],
                                ],
                            ],
                        ], JSON_THROW_ON_ERROR),
                    ],
                ],
            ],
            'usage' => ['prompt_tokens' => 80, 'completion_tokens' => 25],
        ];
    }

    private function deadline(): Deadline
    {
        return new Deadline(new SystemMonotonicClock(), 8000);
    }

    /**
     * @param array<string, mixed> $source
     * @param list<int|string> $path
     */
    private function stringPath(array $source, array $path): string
    {
        $value = $this->valuePath($source, $path);
        if (!is_string($value)) {
            $this->fail('Expected payload path to contain a string.');
        }

        return $value;
    }

    /**
     * @param array<string, mixed> $source
     * @param list<int|string> $path
     */
    private function boolPath(array $source, array $path): bool
    {
        $value = $this->valuePath($source, $path);
        if (!is_bool($value)) {
            $this->fail('Expected payload path to contain a boolean.');
        }

        return $value;
    }

    /**
     * @param array<string, mixed> $source
     * @param list<int|string> $path
     * @return array<mixed>
     */
    private function arrayPath(array $source, array $path): array
    {
        $value = $this->valuePath($source, $path);
        if (!is_array($value)) {
            $this->fail('Expected payload path to contain an array.');
        }

        return $value;
    }

    /**
     * @param array<string, mixed> $source
     * @param list<int|string> $path
     */
    private function valuePath(array $source, array $path): mixed
    {
        $value = $source;
        foreach ($path as $segment) {
            if (!is_array($value) || !array_key_exists($segment, $value)) {
                $this->fail('Expected payload path was missing.');
            }
            $value = $value[$segment];
        }

        return $value;
    }
}

final class TestPdfPageRenderer implements PdfPageRenderer
{
    public ?string $lastPdfBytes = null;
    public ?int $lastMaxPages = null;

    public function render(string $pdfBytes, int $maxPages): array
    {
        $this->lastPdfBytes = $pdfBytes;
        $this->lastMaxPages = $maxPages;

        return [new RenderedPdfPage(1, 'image/png', 'page-1')];
    }
}

final class OpenAiProviderTextContentNormalizer implements DocumentContentNormalizer
{
    public function supports(DocumentContentNormalizationRequest $request): bool
    {
        return true;
    }

    public function normalize(
        DocumentContentNormalizationRequest $request,
        Deadline $deadline,
    ): NormalizedDocumentContent {
        return new NormalizedDocumentContent(
            NormalizedDocumentSource::fromLoadResult($request->document, $request->documentType),
            textSections: [
                new NormalizedTextSection('section-1', 'Medications', 'Current medications: aspirin', 'section:meds'),
            ],
            tables: [
                new NormalizedTable('table-1', 'Medication Table', ['Medication'], [['Medication' => 'aspirin']]),
            ],
            messageSegments: [
                new NormalizedMessageSegment('segment-1', 'PID', ['PID.5' => 'Jane Doe']),
            ],
            normalizer: 'test-text',
        );
    }

    public function name(): string
    {
        return 'test-text';
    }
}

final class OpenAiProviderRenderedFaxNormalizer implements DocumentContentNormalizer
{
    public function supports(DocumentContentNormalizationRequest $request): bool
    {
        return $request->documentType === DocumentType::FaxPacket;
    }

    public function normalize(
        DocumentContentNormalizationRequest $request,
        Deadline $deadline,
    ): NormalizedDocumentContent {
        return new NormalizedDocumentContent(
            NormalizedDocumentSource::fromLoadResult($request->document, $request->documentType),
            renderedPages: [
                new NormalizedRenderedPage(1, 'image/png', 'tiff-page-1'),
                new NormalizedRenderedPage(2, 'image/png', 'tiff-page-2'),
            ],
            normalizer: 'tiff',
            normalizationElapsedMs: 17,
        );
    }

    public function name(): string
    {
        return 'tiff';
    }
}

final class OpenAiProviderReferralDocxNormalizer implements DocumentContentNormalizer
{
    public function supports(DocumentContentNormalizationRequest $request): bool
    {
        return $request->documentType === DocumentType::ReferralDocx;
    }

    public function normalize(
        DocumentContentNormalizationRequest $request,
        Deadline $deadline,
    ): NormalizedDocumentContent {
        return new NormalizedDocumentContent(
            NormalizedDocumentSource::fromLoadResult($request->document, $request->documentType),
            textSections: [
                new NormalizedTextSection('paragraph:3', 'reason-for-referral', 'Cardiology consult requested', 'section:reason-for-referral; paragraph:3'),
            ],
            tables: [
                new NormalizedTable('table:1', 'pertinent-labs', ['test'], [['test' => 'LDL', '_anchor' => 'table:1.row:1']]),
            ],
            normalizer: 'docx',
            normalizationElapsedMs: 17,
        );
    }

    public function name(): string
    {
        return 'docx';
    }
}

final class OpenAiProviderWorkbookNormalizer implements DocumentContentNormalizer
{
    public function supports(DocumentContentNormalizationRequest $request): bool
    {
        return $request->documentType === DocumentType::ClinicalWorkbook;
    }

    public function normalize(
        DocumentContentNormalizationRequest $request,
        Deadline $deadline,
    ): NormalizedDocumentContent {
        return new NormalizedDocumentContent(
            NormalizedDocumentSource::fromLoadResult($request->document, $request->documentType),
            textSections: [
                new NormalizedTextSection('Patient!B2', 'Patient', 'Name: Margaret Chen', 'sheet:Patient; Patient!B2'),
            ],
            tables: [
                new NormalizedTable('sheet:Labs_Trend', 'Labs_Trend', ['test', '2026_04_12'], [[
                    '_anchor' => 'Labs_Trend!A3:H3',
                    'test' => 'LDL cholesterol (calc)',
                    '_cell_test' => 'Labs_Trend!A3',
                    '2026_04_12' => '142',
                    '_cell_2026_04_12' => 'Labs_Trend!H3',
                ]]),
            ],
            normalizer: 'xlsx',
            normalizationElapsedMs: 17,
        );
    }

    public function name(): string
    {
        return 'xlsx';
    }
}

final class RecordingExtractionOpenAiClient implements ClientInterface
{
    /** @var array<string, mixed>|null */
    private ?array $payload = null;

    /** @param array<string, mixed> $responseBody */
    public function __construct(private readonly array $responseBody)
    {
    }

    /** @param array<mixed> $options */
    public function send(RequestInterface $request, array $options = []): ResponseInterface
    {
        throw new BadMethodCallException('send is not used by this test client.');
    }

    /** @param array<mixed> $options */
    public function sendAsync(RequestInterface $request, array $options = []): PromiseInterface
    {
        throw new BadMethodCallException('sendAsync is not used by this test client.');
    }

    /** @param array<mixed> $options */
    public function request(string $method, $uri, array $options = []): ResponseInterface
    {
        $json = $options['json'] ?? null;
        if (!is_array($json)) {
            throw new BadMethodCallException('Expected JSON request payload.');
        }
        $this->payload = $this->stringKeyedArray($json);

        return new Response(200, [], json_encode($this->responseBody, JSON_THROW_ON_ERROR));
    }

    /** @param array<mixed> $options */
    public function requestAsync(string $method, $uri, array $options = []): PromiseInterface
    {
        throw new BadMethodCallException('requestAsync is not used by this test client.');
    }

    public function getConfig(?string $option = null): mixed
    {
        return null;
    }

    /** @return array<string, mixed> */
    public function lastPayload(): array
    {
        if ($this->payload === null) {
            throw new BadMethodCallException('No request payload was recorded.');
        }

        return $this->payload;
    }

    /**
     * @param array<mixed> $source
     * @return array<string, mixed>
     */
    private function stringKeyedArray(array $source): array
    {
        $out = [];
        foreach ($source as $key => $value) {
            if (!is_string($key)) {
                throw new BadMethodCallException('Expected string-keyed request payload.');
            }
            $out[$key] = $value;
        }

        return $out;
    }
}
