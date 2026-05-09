<?php

/**
 * Typed locator for an AgentForge cited document source review.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\AgentForge\Document\SourceReview;

final readonly class ReviewLocator
{
    /**
     * @param array<string, mixed> $fields
     */
    public function __construct(
        public ReviewLocatorKind $kind,
        private array $fields,
    ) {
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return ['kind' => $this->kind->value, ...$this->fields];
    }
}
