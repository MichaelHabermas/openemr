<?php

/**
 * Bounded evidence bundle for draft generation and verification.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\AgentForge;

final readonly class EvidenceBundle
{
    /** @var list<EvidenceBundleItem> */
    public array $items;

    /** @var list<string> */
    public array $missingSections;

    /** @var list<string> */
    public array $failedSections;

    /**
     * @param list<mixed> $items
     * @param list<mixed> $missingSections
     * @param list<mixed> $failedSections
     */
    public function __construct(
        array $items,
        array $missingSections = [],
        array $failedSections = [],
    ) {
        $validatedItems = [];
        foreach ($items as $item) {
            if (!$item instanceof EvidenceBundleItem) {
                throw new \DomainException('Evidence bundle items must be evidence bundle items.');
            }
            $validatedItems[] = $item;
        }
        $validatedMissingSections = [];
        $validatedFailedSections = [];
        foreach ($missingSections as $section) {
            if (!is_string($section) || trim($section) === '') {
                throw new \DomainException('Evidence bundle section messages must be non-empty strings.');
            }
            $validatedMissingSections[] = $section;
        }
        foreach ($failedSections as $section) {
            if (!is_string($section) || trim($section) === '') {
                throw new \DomainException('Evidence bundle section messages must be non-empty strings.');
            }
            $validatedFailedSections[] = $section;
        }

        $this->items = $validatedItems;
        $this->missingSections = $validatedMissingSections;
        $this->failedSections = $validatedFailedSections;
    }

    /** @param list<EvidenceResult> $results */
    public static function fromEvidenceResults(array $results): self
    {
        $items = [];
        $missingSections = [];
        $failedSections = [];

        foreach ($results as $result) {
            foreach ($result->items as $item) {
                $items[] = EvidenceBundleItem::fromEvidenceItem($item);
            }
            $missingSections = array_merge($missingSections, $result->missingSections);
            $failedSections = array_merge($failedSections, $result->failedSections);
        }

        return new self(
            $items,
            array_values(array_unique($missingSections)),
            array_values(array_unique($failedSections)),
        );
    }

    /** @return array<string, EvidenceBundleItem> */
    public function itemsBySourceId(): array
    {
        $items = [];
        foreach ($this->items as $item) {
            $items[$item->sourceId] = $item;
        }

        return $items;
    }

    /** @return list<string> */
    public function citations(): array
    {
        return array_values(array_unique(array_map(
            static fn (EvidenceBundleItem $item): string => $item->sourceId,
            $this->items,
        )));
    }

    /**
     * @return array{
     *     evidence: list<array{
     *         source_type: string,
     *         source_id: string,
     *         source_date: string,
     *         display_label: string,
     *         value: string
     *     }>,
     *     missing_sections: list<string>,
     *     failed_sections: list<string>
     * }
     */
    public function toPromptArray(): array
    {
        return [
            'evidence' => array_map(
                static fn (EvidenceBundleItem $item): array => $item->toArray(),
                $this->items,
            ),
            'missing_sections' => $this->missingSections,
            'failed_sections' => $this->failedSections,
        ];
    }
}
