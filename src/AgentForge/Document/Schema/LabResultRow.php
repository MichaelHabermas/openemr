<?php

/**
 * One structured result row extracted from a lab PDF.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\AgentForge\Document\Schema;

final readonly class LabResultRow
{
    /**
     * @param Certainty $certainty Model-reported certainty from extraction JSON (audit / schema). Downstream
     *                             policy buckets use {@see CertaintyClassifier::classify()} unless the model
     *                             requests {@see Certainty::NeedsReview}.
     */
    public function __construct(
        public string $testName,
        public string $value,
        public string $unit,
        public string $referenceRange,
        public string $collectedAt,
        public AbnormalFlag $abnormalFlag,
        public Certainty $certainty,
        public float $confidence,
        public DocumentCitation $citation,
    ) {
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data, string $path = 'results[0]'): self
    {
        SchemaReader::assertNoUnknownFields(
            $data,
            ['test_name', 'value', 'unit', 'reference_range', 'collected_at', 'abnormal_flag', 'certainty', 'confidence', 'citation'],
            $path,
        );

        $abnormalFlag = AbnormalFlag::tryFrom(SchemaReader::requiredString($data, 'abnormal_flag', $path));
        if ($abnormalFlag === null) {
            throw new ExtractionSchemaException(SchemaReader::join($path, 'abnormal_flag'), 'Expected supported abnormal flag.');
        }

        $certainty = Certainty::tryFrom(SchemaReader::requiredString($data, 'certainty', $path));
        if ($certainty === null) {
            throw new ExtractionSchemaException(SchemaReader::join($path, 'certainty'), 'Expected supported certainty.');
        }

        $value = SchemaReader::requiredString($data, 'value', $path);
        $unit = SchemaReader::requiredString($data, 'unit', $path);
        if ($unit !== '' && preg_match('/^(.+?)\s+' . preg_quote($unit, '/') . '$/i', $value, $matches) === 1) {
            $value = trim($matches[1]);
        }

        return new self(
            SchemaReader::requiredString($data, 'test_name', $path),
            $value,
            $unit,
            SchemaReader::requiredString($data, 'reference_range', $path),
            SchemaReader::requiredString($data, 'collected_at', $path),
            $abnormalFlag,
            $certainty,
            SchemaReader::requiredConfidence($data, 'confidence', $path),
            DocumentCitation::fromArray(SchemaReader::requiredObject($data, 'citation', $path), SchemaReader::join($path, 'citation')),
        );
    }
}
