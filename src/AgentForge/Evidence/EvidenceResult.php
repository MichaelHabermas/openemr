<?php

/**
 * Result from one read-only AgentForge evidence tool.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\AgentForge\Evidence;

final readonly class EvidenceResult
{
    /**
     * @param list<EvidenceItem> $items
     * @param list<string> $missingSections
     * @param list<string> $failedSections
     */
    public function __construct(
        public string $section,
        public array $items = [],
        public array $missingSections = [],
        public array $failedSections = [],
    ) {
    }

    /** @param list<EvidenceItem> $items */
    public static function found(string $section, array $items): self
    {
        if ($items === []) {
            return self::missing($section, sprintf('%s not found in the chart.', $section));
        }

        return new self($section, $items);
    }

    public static function missing(string $section, string $message): self
    {
        return new self($section, [], [$message]);
    }

    public static function failure(string $section, string $message): self
    {
        return new self($section, [], [], [$message]);
    }

    /** @return list<string> */
    public function citations(): array
    {
        return array_map(
            static fn (EvidenceItem $item): string => $item->citation(),
            $this->items,
        );
    }
}
