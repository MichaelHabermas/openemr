<?php

/**
 * Bounded evidence item allowed to cross the model boundary.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\AgentForge\Evidence;

use DomainException;

final readonly class EvidenceBundleItem
{
    public const MAX_VALUE_LENGTH = 500;

    /** @param array<string, mixed> $citation */
    public function __construct(
        public string $sourceType,
        public string $sourceId,
        public string $sourceDate,
        public string $displayLabel,
        public string $value,
        public array $citation = [],
    ) {
        $this->assertPresent($sourceType, 'source type');
        $this->assertPresent($sourceId, 'source id');
        $this->assertPresent($sourceDate, 'source date');
        $this->assertPresent($displayLabel, 'display label');
        $this->assertPresent($value, 'value');

        if (strlen($value) > self::MAX_VALUE_LENGTH) {
            throw new DomainException('Evidence bundle value exceeds the model boundary limit.');
        }
    }

    public static function fromEvidenceItem(EvidenceItem $item): self
    {
        return new self(
            $item->sourceType,
            $item->citation(),
            $item->sourceDate,
            EvidenceText::bounded($item->displayLabel, 160),
            EvidenceText::bounded($item->value, self::MAX_VALUE_LENGTH),
        );
    }

    /**
     * @return array{
     *     source_type: string,
     *     source_id: string,
     *     source_date: string,
     *     display_label: string,
     *     value: string,
     *     citation?: array<string, mixed>
     * }
     */
    public function toArray(): array
    {
        $out = [
            'source_type' => $this->sourceType,
            'source_id' => $this->sourceId,
            'source_date' => $this->sourceDate,
            'display_label' => $this->displayLabel,
            'value' => $this->value,
        ];
        if ($this->citation !== []) {
            return $out + ['citation' => $this->citation];
        }

        return $out;
    }

    private function assertPresent(string $value, string $label): void
    {
        if (trim($value) === '') {
            throw new DomainException(sprintf('Evidence bundle %s is required.', $label));
        }
    }
}
