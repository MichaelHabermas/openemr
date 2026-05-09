<?php

/**
 * Deterministic HL7 v2 extraction for supported ADT and ORU message shapes.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\AgentForge\Document\Extraction;

use JsonException;
use OpenEMR\AgentForge\Deadline;
use OpenEMR\AgentForge\Document\Content\DocumentContentNormalizationException;
use OpenEMR\AgentForge\Document\Content\DocumentContentNormalizationRequest;
use OpenEMR\AgentForge\Document\Content\Hl7v2DocumentContentNormalizer;
use OpenEMR\AgentForge\Document\Content\NormalizedMessageSegment;
use OpenEMR\AgentForge\Document\DocumentId;
use OpenEMR\AgentForge\Document\DocumentType;
use OpenEMR\AgentForge\Document\ExtractionErrorCode;
use OpenEMR\AgentForge\Document\Worker\DocumentLoadResult;
use OpenEMR\AgentForge\ResponseGeneration\DraftUsage;
use OpenEMR\AgentForge\Time\SystemMonotonicClock;

final class Hl7v2MessageExtractionProvider implements DocumentExtractionProvider
{
    public function __construct(
        private readonly Hl7v2DocumentContentNormalizer $normalizer = new Hl7v2DocumentContentNormalizer(new SystemMonotonicClock()),
    ) {
    }

    public function extract(
        DocumentId $documentId,
        DocumentLoadResult $document,
        DocumentType $documentType,
        Deadline $deadline,
    ): ExtractionProviderResponse {
        if ($documentType !== DocumentType::Hl7v2Message) {
            throw new ExtractionProviderException(
                'HL7 v2 deterministic extraction only supports hl7v2_message.',
                ExtractionErrorCode::UnsupportedDocType,
            );
        }
        if ($deadline->exceeded()) {
            throw new ExtractionProviderException('Deadline exceeded before HL7 v2 extraction.');
        }

        try {
            $content = $this->normalizer->normalize(
                new DocumentContentNormalizationRequest($documentId, $documentType, $document),
                $deadline,
            );
        } catch (DocumentContentNormalizationException $exception) {
            throw new ExtractionProviderException($exception->getMessage(), $exception->errorCode, $exception);
        }

        return $this->extractSegments(
            $content->messageSegments,
            hash('sha256', $document->bytes),
            $content->telemetry()->toLogContext(),
        );
    }

    /**
     * @param list<NormalizedMessageSegment> $segments
     * @param array<string, mixed> $normalizationTelemetry
     */
    public function extractSegments(array $segments, string $sourceSha256, array $normalizationTelemetry = []): ExtractionProviderResponse
    {
        $parsed = Hl7v2ParsedMessage::fromSegments($segments, $sourceSha256);
        $payload = $parsed->toExtractionPayload();

        try {
            return ExtractionProviderResponse::fromStrictJson(
                DocumentType::Hl7v2Message,
                json_encode($payload, JSON_THROW_ON_ERROR),
                DraftUsage::fixture(),
                'deterministic-hl7v2',
                $parsed->warnings,
                normalizationTelemetry: $normalizationTelemetry,
            );
        } catch (JsonException $exception) {
            throw new ExtractionProviderException(
                'HL7 v2 deterministic extraction failed to encode strict JSON.',
                ExtractionErrorCode::SchemaValidationFailure,
                $exception,
            );
        }
    }
}
