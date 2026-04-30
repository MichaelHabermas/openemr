<?php

/**
 * Source-carrying AgentForge chart evidence item.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\AgentForge;

use DomainException;

final readonly class EvidenceItem
{
    public function __construct(
        public string $sourceType,
        public string $sourceTable,
        public string $sourceId,
        public string $sourceDate,
        public string $displayLabel,
        public string $value,
    ) {
        $this->assertPresent($sourceType, 'source type');
        $this->assertPresent($sourceTable, 'source table');
        $this->assertPresent($sourceId, 'source row id');
        $this->assertPresent($sourceDate, 'source date');
        $this->assertValidSourceDate($sourceDate);
        $this->assertPresent($displayLabel, 'display label');
        $this->assertPresent($value, 'value');
    }

    /**
     * @return array{
     *     source_type: string,
     *     source_table: string,
     *     source_id: string,
     *     source_date: string,
     *     display_label: string,
     *     value: string
     * }
     */
    public function toArray(): array
    {
        return [
            'source_type' => $this->sourceType,
            'source_table' => $this->sourceTable,
            'source_id' => $this->sourceId,
            'source_date' => $this->sourceDate,
            'display_label' => $this->displayLabel,
            'value' => $this->value,
        ];
    }

    public function citation(): string
    {
        return sprintf(
            '%s:%s/%s@%s',
            $this->sourceType,
            $this->sourceTable,
            $this->sourceId,
            $this->sourceDate,
        );
    }

    private function assertPresent(string $value, string $label): void
    {
        if (trim($value) === '') {
            throw new DomainException(sprintf('Evidence %s is required.', $label));
        }
    }

    private function assertValidSourceDate(string $sourceDate): void
    {
        if ($sourceDate === 'unknown') {
            return;
        }

        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $sourceDate);
        if ($date === false || $date->format('Y-m-d') !== $sourceDate) {
            throw new DomainException('Evidence source date must be Y-m-d or "unknown".');
        }
    }
}
