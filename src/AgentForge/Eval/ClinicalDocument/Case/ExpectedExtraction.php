<?php

/**
 * Clinical document eval support for AgentForge.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\AgentForge\Eval\ClinicalDocument\Case;

use OpenEMR\AgentForge\StringKeyedArray;

final readonly class ExpectedExtraction
{
    /**
     * @param list<array<string, mixed>> $facts
     */
    public function __construct(
        public bool $schemaValid,
        public array $facts = [],
    ) {
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            (bool) ($data['schema_valid'] ?? false),
            self::listOfArrays($data['facts'] ?? []),
        );
    }

    /**
     * @param mixed $value
     * @return list<array<string, mixed>>
     */
    private static function listOfArrays(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        $items = [];
        foreach ($value as $item) {
            if (is_array($item)) {
                $items[] = StringKeyedArray::filter($item);
            }
        }

        return $items;
    }
}
