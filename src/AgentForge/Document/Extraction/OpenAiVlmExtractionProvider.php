<?php

/**
 * OpenAI VLM-backed clinical document extraction provider.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\AgentForge\Document\Extraction;

use DomainException;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;
use JsonException;
use OpenEMR\AgentForge\Deadline;
use OpenEMR\AgentForge\Document\Content\DocumentContentNormalizationException;
use OpenEMR\AgentForge\Document\Content\DocumentContentNormalizationRequest;
use OpenEMR\AgentForge\Document\Content\DocumentContentNormalizerRegistry;
use OpenEMR\AgentForge\Document\Content\DocumentContentNormalizerRegistryFactory;
use OpenEMR\AgentForge\Document\Content\NormalizedDocumentContent;
use OpenEMR\AgentForge\Document\Content\NormalizedMessageSegment;
use OpenEMR\AgentForge\Document\Content\NormalizedTable;
use OpenEMR\AgentForge\Document\Content\NormalizedTextSection;
use OpenEMR\AgentForge\Document\DocumentId;
use OpenEMR\AgentForge\Document\DocumentType;
use OpenEMR\AgentForge\Document\ExtractionErrorCode;
use OpenEMR\AgentForge\Document\Schema\ExtractionSchemaException;
use OpenEMR\AgentForge\Document\Worker\DocumentLoadResult;
use OpenEMR\AgentForge\Llm\LlmCredentialGuard;
use OpenEMR\AgentForge\Llm\TokenCostEstimator;
use OpenEMR\AgentForge\ResponseGeneration\DraftUsage;
use Psr\Http\Message\ResponseInterface;
use SensitiveParameter;

final readonly class OpenAiVlmExtractionProvider implements DocumentExtractionProvider
{
    public function __construct(
        private ClientInterface $client,
        #[SensitiveParameter] private string $apiKey,
        private string $model,
        private PdfPageRenderer $pdfRenderer,
        private JsonSchemaBuilder $schemaBuilder = new JsonSchemaBuilder(),
        private ?float $inputCostPerMillionTokens = null,
        private ?float $outputCostPerMillionTokens = null,
        private float $configuredTimeoutSeconds = 30.0,
        private int $maxPdfPages = 6,
        private ?DocumentContentNormalizerRegistry $contentNormalizers = null,
    ) {
        LlmCredentialGuard::requireApiKey($apiKey, 'OpenAI extraction provider');
        LlmCredentialGuard::requireModel($model, 'OpenAI extraction provider');
        if ($configuredTimeoutSeconds <= 0.0) {
            throw new DomainException('OpenAI extraction timeout must be greater than zero.');
        }
        if ($maxPdfPages < 1) {
            throw new DomainException('OpenAI extraction max PDF pages must be positive.');
        }
    }

    public function extract(
        DocumentId $documentId,
        DocumentLoadResult $document,
        DocumentType $documentType,
        Deadline $deadline,
    ): ExtractionProviderResponse {
        if ($deadline->exceeded()) {
            throw new ExtractionProviderException('Deadline exceeded before OpenAI extraction request.');
        }

        $timeoutSeconds = min($this->configuredTimeoutSeconds, $deadline->remainingSeconds());
        try {
            $content = $this->contentNormalizers()->normalize(
                new DocumentContentNormalizationRequest($documentId, $documentType, $document),
                $deadline,
            );
        } catch (DocumentContentNormalizationException $exception) {
            throw new ExtractionProviderException(
                $exception->getMessage(),
                $exception->errorCode,
                $exception,
            );
        }

        try {
            $response = $this->client->request('POST', '/v1/chat/completions', [
                'headers' => [
                    'Authorization' => sprintf('Bearer %s', $this->apiKey),
                    'Content-Type' => 'application/json',
                ],
                'json' => $this->payload($documentType, $content),
                'timeout' => $timeoutSeconds,
            ]);
        } catch (GuzzleException $exception) {
            throw new ExtractionProviderException('OpenAI extraction request failed.', previous: $exception);
        }

        return $this->parseResponse($response, $documentType, $content->telemetry()->toLogContext());
    }

    /** @return array<string, mixed> */
    private function payload(DocumentType $documentType, NormalizedDocumentContent $content): array
    {
        return [
            'model' => $this->model,
            'messages' => [
                [
                    'role' => 'system',
                    'content' => $this->systemPrompt($documentType),
                ],
                [
                    'role' => 'user',
                    'content' => $this->contentParts($content),
                ],
            ],
            'response_format' => [
                'type' => 'json_schema',
                'json_schema' => [
                    'name' => JsonSchemaBuilder::SCHEMA_NAME,
                    'strict' => true,
                    'schema' => $this->schemaBuilder->schema($documentType),
                ],
            ],
            'temperature' => 0,
        ];
    }

    private function systemPrompt(DocumentType $documentType): string
    {
        return implode("\n", [
            'You extract structured facts from one clinical document inside OpenEMR AgentForge.',
            'Use only the supplied document image or text content.',
            'Return only valid JSON matching the response schema.',
            'Every extracted value must include a citation with source_type, source_id, page_or_section, field_or_chunk_id, quote_or_value, and any visible normalized bounding_box.',
            'Use certainty values verified, document_fact, or needs_review only.',
            sprintf('Requested document type: %s.', $documentType->value),
        ]);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function contentParts(NormalizedDocumentContent $content): array
    {
        $parts = [[
            'type' => 'text',
            'text' => sprintf(
                'Extract cited clinical facts from the supplied %s content (sha256=%s). Do not infer beyond the visible document.',
                $content->source->mimeType,
                $content->source->sha256,
            ),
        ]];

        foreach ($content->renderedPages as $page) {
            $parts[] = [
                'type' => 'image_url',
                'image_url' => ['url' => $page->dataUrl()],
            ];
        }

        foreach ($content->textSections as $section) {
            $parts[] = [
                'type' => 'text',
                'text' => $this->textSectionPart($section),
            ];
        }

        foreach ($content->tables as $table) {
            $parts[] = [
                'type' => 'text',
                'text' => $this->tablePart($table),
            ];
        }

        foreach ($content->messageSegments as $segment) {
            $parts[] = [
                'type' => 'text',
                'text' => $this->messageSegmentPart($segment),
            ];
        }

        return $parts;
    }

    private function textSectionPart(NormalizedTextSection $section): string
    {
        return sprintf(
            "Normalized text section %s (%s, source=%s):\n%s",
            $section->sectionId,
            $section->title,
            $section->sourceReference,
            $section->text,
        );
    }

    private function tablePart(NormalizedTable $table): string
    {
        return sprintf(
            "Normalized table %s (%s):\n%s",
            $table->tableId,
            $table->title,
            json_encode(['columns' => $table->columns, 'rows' => $table->rows], JSON_THROW_ON_ERROR),
        );
    }

    private function messageSegmentPart(NormalizedMessageSegment $segment): string
    {
        return sprintf(
            "Normalized message segment %s (%s):\n%s",
            $segment->segmentId,
            $segment->segmentType,
            json_encode($segment->fields, JSON_THROW_ON_ERROR),
        );
    }

    /** @param array<string, mixed> $normalizationTelemetry */
    private function parseResponse(
        ResponseInterface $response,
        DocumentType $documentType,
        array $normalizationTelemetry = [],
    ): ExtractionProviderResponse
    {
        $statusCode = $response->getStatusCode();
        if ($statusCode < 200 || $statusCode >= 300) {
            throw new ExtractionProviderException(
                sprintf('OpenAI extraction response returned HTTP %d.', $statusCode),
                ExtractionErrorCode::ExtractionFailure,
            );
        }

        $body = $this->jsonObject((string) $response->getBody(), 'OpenAI extraction response');

        $choices = $this->arrayField($body, 'choices', 'OpenAI extraction response');
        $firstChoice = $choices[0] ?? null;
        if (!is_array($firstChoice)) {
            throw new ExtractionProviderException('OpenAI extraction response did not include a choice.');
        }

        $message = $this->objectFromMixed($firstChoice['message'] ?? null, 'OpenAI extraction response message');
        $content = $message['content'] ?? null;
        if (!is_string($content) || trim($content) === '') {
            throw new ExtractionProviderException('OpenAI extraction response content was empty.');
        }

        try {
            return ExtractionProviderResponse::fromStrictJson(
                $documentType,
                $content,
                $this->usageFromResponse($body),
                $this->model,
                normalizationTelemetry: $normalizationTelemetry,
            );
        } catch (ExtractionSchemaException $exception) {
            throw new ExtractionProviderException(
                'OpenAI extraction content failed strict schema validation.',
                ExtractionErrorCode::SchemaValidationFailure,
                $exception,
            );
        }
    }

    private function contentNormalizers(): DocumentContentNormalizerRegistry
    {
        if ($this->contentNormalizers !== null) {
            return $this->contentNormalizers;
        }

        return DocumentContentNormalizerRegistryFactory::default($this->pdfRenderer, $this->maxPdfPages);
    }

    /** @return array<string, mixed> */
    private function jsonObject(string $json, string $label): array
    {
        try {
            $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new ExtractionProviderException(sprintf('%s was invalid JSON.', $label), previous: $exception);
        }

        return $this->objectFromMixed($decoded, $label);
    }

    /** @return array<string, mixed> */
    private function objectFromMixed(mixed $value, string $label): array
    {
        if (!is_array($value)) {
            throw new ExtractionProviderException(sprintf('%s was not an object.', $label));
        }

        $object = [];
        foreach ($value as $key => $field) {
            if (!is_string($key)) {
                throw new ExtractionProviderException(sprintf('%s was not an object.', $label));
            }
            $object[$key] = $field;
        }

        return $object;
    }

    /**
     * @param array<string, mixed> $source
     * @return list<mixed>
     */
    private function arrayField(array $source, string $key, string $label): array
    {
        $value = $source[$key] ?? null;
        if (!is_array($value)) {
            throw new ExtractionProviderException(sprintf('%s %s must be an array.', $label, $key));
        }

        return array_values($value);
    }

    /** @param array<string, mixed> $body */
    private function usageFromResponse(array $body): DraftUsage
    {
        $usage = $body['usage'] ?? [];
        $inputTokens = $this->intFromUsage($usage, 'prompt_tokens');
        $outputTokens = $this->intFromUsage($usage, 'completion_tokens');

        return new DraftUsage(
            $this->model,
            $inputTokens,
            $outputTokens,
            TokenCostEstimator::estimate(
                $inputTokens,
                $outputTokens,
                $this->inputCostPerMillionTokens,
                $this->outputCostPerMillionTokens,
            ),
        );
    }

    private function intFromUsage(mixed $usage, string $key): int
    {
        if (!is_array($usage) || !isset($usage[$key]) || !is_int($usage[$key])) {
            return 0;
        }

        return $usage[$key];
    }

}
