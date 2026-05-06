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
use OpenEMR\AgentForge\Document\Schema\BoundingBox;
use OpenEMR\AgentForge\Document\Schema\DocumentCitation;
use OpenEMR\AgentForge\Document\Schema\IntakeFormExtraction;
use OpenEMR\AgentForge\Document\Schema\IntakeFormFinding;
use OpenEMR\AgentForge\Document\Schema\LabPdfExtraction;
use OpenEMR\AgentForge\Document\Schema\LabResultRow;
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
     */
    public function __construct(
        public bool $schemaValid,
        array $facts,
        array $warnings,
        public DraftUsage $usage,
        public ?string $model = null,
        public ?string $rawText = null,
        public LabPdfExtraction | IntakeFormExtraction | null $extraction = null,
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
     */
    public static function fromStrictJson(
        DocumentType $documentType,
        string $json,
        DraftUsage $usage,
        ?string $model = null,
        array $warnings = [],
    ): self {
        $extraction = match ($documentType) {
            DocumentType::LabPdf => LabPdfExtraction::fromJson($json),
            DocumentType::IntakeForm => IntakeFormExtraction::fromJson($json),
        };

        return new self(
            true,
            self::factsFromExtraction($extraction),
            $warnings,
            $usage,
            $model,
            $json,
            $extraction,
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
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function factsFromExtraction(LabPdfExtraction | IntakeFormExtraction $extraction): array
    {
        if ($extraction instanceof LabPdfExtraction) {
            $facts = [];
            foreach ($extraction->results as $index => $row) {
                $facts[] = self::factFromLabRow($row, $index);
            }

            return $facts;
        }

        $facts = [];
        foreach ($extraction->findings as $finding) {
            $facts[] = self::factFromIntakeFinding($finding);
        }

        return $facts;
    }

    /**
     * @return array<string, mixed>
     */
    private static function factFromLabRow(LabResultRow $row, int $index): array
    {
        return [
            'type' => 'lab_result',
            'field_path' => sprintf('results[%d]', $index),
            'test_name' => $row->testName,
            'label' => $row->testName,
            'value' => $row->value,
            'unit' => $row->unit,
            'reference_range' => $row->referenceRange,
            'collected_at' => $row->collectedAt,
            'abnormal_flag' => $row->abnormalFlag->value,
            'certainty' => $row->certainty->value,
            'confidence' => $row->confidence,
            'citation' => self::citationToArray($row->citation),
            'bounding_box' => self::boundingBoxToArray($row->citation->boundingBox),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function factFromIntakeFinding(IntakeFormFinding $finding): array
    {
        return [
            'type' => 'intake_finding',
            'field_path' => $finding->field,
            'label' => $finding->field,
            'value' => $finding->value,
            'certainty' => $finding->certainty->value,
            'confidence' => $finding->confidence,
            'citation' => self::citationToArray($finding->citation),
            'bounding_box' => self::boundingBoxToArray($finding->citation->boundingBox),
        ];
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
