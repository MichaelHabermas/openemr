<?php

/**
 * Pure data transformation for clinical document promotion values.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\AgentForge\Document\Promotion;

use DateTimeImmutable;
use OpenEMR\AgentForge\Document\Schema\BoundingBox;
use OpenEMR\AgentForge\Document\Schema\DocumentCitation;
use OpenEMR\AgentForge\Document\Schema\IntakeFormFinding;
use OpenEMR\AgentForge\Document\Schema\LabResultRow;

final readonly class PromotionValueSerializer
{
    /** @return array<string, mixed> */
    public function labValueJson(LabResultRow $row): array
    {
        return [
            'test_name' => $row->testName,
            'value' => $row->value,
            'unit' => $row->unit,
            'reference_range' => $row->referenceRange,
            'collected_at' => $row->collectedAt,
            'abnormal_flag' => $row->abnormalFlag->value,
            'certainty' => $row->certainty->value,
            'confidence' => $row->confidence,
        ];
    }

    /** @return array<string, mixed> */
    public function findingValueJson(IntakeFormFinding $finding): array
    {
        return [
            'field' => $finding->field,
            'value' => $finding->value,
            'certainty' => $finding->certainty->value,
            'confidence' => $finding->confidence,
        ];
    }

    /** @return array<string, mixed> */
    public function stableLabValueJson(LabResultRow $row): array
    {
        return [
            'test_name' => strtolower($row->testName),
            'value' => $row->value,
            'unit' => strtolower($row->unit),
            'reference_range' => $row->referenceRange,
            'collected_at' => substr($row->collectedAt, 0, 10),
            'abnormal_flag' => $row->abnormalFlag->value,
        ];
    }

    /** @return array<string, mixed> */
    public function stableFindingValueJson(IntakeFormFinding $finding): array
    {
        return [
            'field' => strtolower($finding->field),
            'value' => strtolower($finding->value),
        ];
    }

    /** @return array<string, mixed> */
    public function citationJson(DocumentCitation $citation): array
    {
        return [
            'source_type' => $citation->sourceType->value,
            'source_id' => $citation->sourceId,
            'page_or_section' => $citation->pageOrSection,
            'field_or_chunk_id' => $citation->fieldOrChunkId,
            'quote_or_value' => $citation->quoteOrValue,
            'bounding_box' => $citation->boundingBox === null ? null : $this->boundingBoxJson($citation->boundingBox),
        ];
    }

    /** @return array<string, float> */
    public function boundingBoxJson(BoundingBox $box): array
    {
        return ['x' => $box->x, 'y' => $box->y, 'width' => $box->width, 'height' => $box->height];
    }

    /** @param array<string, mixed> $data */
    public function json(array $data): string
    {
        return json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    }

    public function dateTimeOrNull(string $value): ?string
    {
        if (preg_match('/^\d{4}-\d{2}-\d{2}/', $value) !== 1) {
            return null;
        }

        return (new DateTimeImmutable($value))->format('Y-m-d H:i:s');
    }

    public function normalizeScalar(?string $value): string
    {
        return strtolower(trim((string) $value));
    }

    /**
     * @param array<string, mixed> $valueJson
     */
    public function legacyFactHash(string $factType, string $label, array $valueJson): string
    {
        return hash('sha256', $this->json([
            'fact_type' => $factType,
            'label' => $label,
            'value' => $valueJson,
        ]));
    }

    /** @param array<string, mixed> $row */
    public function nullableString(array $row, string $key): ?string
    {
        $value = $row[$key] ?? null;
        return is_scalar($value) && trim((string) $value) !== '' ? (string) $value : null;
    }

    /** @param array<string, mixed> $row */
    public function intValue(array $row, string $key): ?int
    {
        $value = $row[$key] ?? null;
        if (is_int($value)) {
            return $value;
        }
        if (is_string($value) && ctype_digit($value)) {
            return (int) $value;
        }

        return null;
    }
}
