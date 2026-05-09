<?php

/**
 * Structured clinical document extraction response.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\AgentForge\Document\Extraction;

use DomainException;
use OpenEMR\AgentForge\Document\DocumentType;
use OpenEMR\AgentForge\Document\Mapping\DocumentFactDraft;
use OpenEMR\AgentForge\Document\Mapping\ClinicalWorkbookFactMapper;
use OpenEMR\AgentForge\Document\Mapping\DocumentFactMapperRegistry;
use OpenEMR\AgentForge\Document\Mapping\FaxPacketFactMapper;
use OpenEMR\AgentForge\Document\Mapping\Hl7v2MessageFactMapper;
use OpenEMR\AgentForge\Document\Mapping\IntakeFormFactMapper;
use OpenEMR\AgentForge\Document\Mapping\LabPdfFactMapper;
use OpenEMR\AgentForge\Document\Mapping\ReferralDocxFactMapper;
use OpenEMR\AgentForge\Document\Schema\BoundingBox;
use OpenEMR\AgentForge\Document\Schema\ClinicalWorkbookExtraction;
use OpenEMR\AgentForge\Document\Schema\DocumentCitation;
use OpenEMR\AgentForge\Document\Schema\FaxPacketExtraction;
use OpenEMR\AgentForge\Document\Schema\Hl7v2MessageExtraction;
use OpenEMR\AgentForge\Document\Schema\IntakeFormExtraction;
use OpenEMR\AgentForge\Document\Schema\LabPdfExtraction;
use OpenEMR\AgentForge\Document\Schema\ReferralDocxExtraction;
use OpenEMR\AgentForge\ResponseGeneration\DraftUsage;
use OpenEMR\AgentForge\StringKeyedArray;

final readonly class ExtractionProviderResponse
{
    /** @var list<array<string, mixed>> */
    public array $facts;

    /** @var list<string> */
    public array $warnings;

    /**
     * @param list<mixed> $facts
     * @param list<mixed> $warnings
     * @param array<string, mixed> $normalizationTelemetry
     */
    public function __construct(
        public bool $schemaValid,
        array $facts,
        array $warnings,
        public DraftUsage $usage,
        public ?string $model = null,
        public ?string $rawText = null,
        public LabPdfExtraction | IntakeFormExtraction | ReferralDocxExtraction | ClinicalWorkbookExtraction | FaxPacketExtraction | Hl7v2MessageExtraction | null $extraction = null,
        public array $normalizationTelemetry = [],
    ) {
        if ($schemaValid && $extraction === null) {
            throw new DomainException('Schema-valid extraction responses must include a parsed extraction value object.');
        }

        $validatedFacts = [];
        foreach ($facts as $fact) {
            if (!is_array($fact)) {
                throw new DomainException('Extraction facts must be objects.');
            }
            $validatedFacts[] = StringKeyedArray::filter($fact);
        }

        $validatedWarnings = [];
        foreach ($warnings as $warning) {
            if (!is_string($warning) || trim($warning) === '') {
                throw new DomainException('Extraction warnings must be non-empty strings.');
            }
            $validatedWarnings[] = $warning;
        }

        $this->facts = $validatedFacts;
        $this->warnings = $validatedWarnings;
    }

    /**
     * @param list<string> $warnings
     * @param array<string, mixed> $normalizationTelemetry
     */
    public static function fromStrictJson(
        DocumentType $documentType,
        string $json,
        DraftUsage $usage,
        ?string $model = null,
        array $warnings = [],
        array $normalizationTelemetry = [],
    ): self {
        $extraction = match ($documentType) {
            DocumentType::LabPdf => LabPdfExtraction::fromJson($json),
            DocumentType::IntakeForm => IntakeFormExtraction::fromJson($json),
            DocumentType::ReferralDocx => ReferralDocxExtraction::fromJson($json),
            DocumentType::ClinicalWorkbook => ClinicalWorkbookExtraction::fromJson($json),
            DocumentType::FaxPacket => FaxPacketExtraction::fromJson($json),
            DocumentType::Hl7v2Message => Hl7v2MessageExtraction::fromJson($json),
        };

        $registry = new DocumentFactMapperRegistry(
            new LabPdfFactMapper(),
            new IntakeFormFactMapper(),
            new ReferralDocxFactMapper(),
            new ClinicalWorkbookFactMapper(),
            new FaxPacketFactMapper(),
            new Hl7v2MessageFactMapper(),
        );

        return new self(
            true,
            self::factsFromDrafts($registry->map($documentType, $extraction)),
            $warnings,
            $usage,
            $model,
            $json,
            $extraction,
            self::safeNormalizationTelemetry($normalizationTelemetry),
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'schema_valid' => $this->schemaValid,
            'facts' => $this->facts,
            'warnings' => $this->warnings,
            'model' => $this->model,
            'normalization' => $this->normalizationTelemetry,
        ];
    }

    /**
     * @param list<DocumentFactDraft> $drafts
     * @return list<array<string, mixed>>
     */
    private static function factsFromDrafts(array $drafts): array
    {
        return array_map(static function (DocumentFactDraft $draft): array {
            $fact = [
                'type' => $draft->factType,
                'field_path' => $draft->fieldPath,
                'label' => $draft->displayLabel,
            ];
            foreach ($draft->structuredValue as $key => $value) {
                if (!isset($fact[$key])) {
                    $fact[$key] = $value;
                }
            }
            $fact['citation'] = self::citationToArray($draft->citation);
            $fact['bounding_box'] = self::boundingBoxToArray($draft->citation->boundingBox);

            return $fact;
        }, $drafts);
    }

    /**
     * @param array<string, mixed> $telemetry
     * @return array<string, mixed>
     */
    private static function safeNormalizationTelemetry(array $telemetry): array
    {
        $out = [];
        foreach ([
            'normalizer',
            'source_mime_type',
        ] as $key) {
            if (isset($telemetry[$key]) && is_string($telemetry[$key])) {
                $out[$key] = $telemetry[$key];
            }
        }

        foreach ([
            'source_byte_count',
            'rendered_page_count',
            'text_section_count',
            'table_count',
            'message_segment_count',
            'normalization_elapsed_ms',
        ] as $key) {
            if (isset($telemetry[$key]) && is_int($telemetry[$key]) && $telemetry[$key] >= 0) {
                $out[$key] = $telemetry[$key];
            }
        }

        if (isset($telemetry['warning_codes']) && is_array($telemetry['warning_codes'])) {
            $warningCodes = [];
            foreach ($telemetry['warning_codes'] as $warningCode) {
                if (is_string($warningCode) && preg_match('/^[a-z0-9_:-]+$/', $warningCode)) {
                    $warningCodes[] = $warningCode;
                }
            }
            $out['warning_codes'] = array_values(array_unique($warningCodes));
        }

        return $out;
    }

    /**
     * @return array<string, mixed>
     */
    private static function citationToArray(DocumentCitation $citation): array
    {
        return [
            'source_type' => $citation->sourceType->value,
            'source_id' => $citation->sourceId,
            'page_or_section' => $citation->pageOrSection,
            'field_or_chunk_id' => $citation->fieldOrChunkId,
            'quote_or_value' => $citation->quoteOrValue,
            'bounding_box' => self::boundingBoxToArray($citation->boundingBox),
        ];
    }

    /**
     * @return array{x: float, y: float, width: float, height: float}|null
     */
    private static function boundingBoxToArray(?BoundingBox $boundingBox): ?array
    {
        if ($boundingBox === null) {
            return null;
        }

        return [
            'x' => $boundingBox->x,
            'y' => $boundingBox->y,
            'width' => $boundingBox->width,
            'height' => $boundingBox->height,
        ];
    }
}
