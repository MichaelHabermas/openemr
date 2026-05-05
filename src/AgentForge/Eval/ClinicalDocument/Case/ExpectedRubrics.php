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

final readonly class ExpectedRubrics
{
    /** @param array<string, bool|null> $expectations */
    public function __construct(public array $expectations)
    {
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        $expectations = [];
        foreach ($data as $key => $value) {
            if ($value === null || is_bool($value)) {
                $expectations[(string) $key] = $value;
            }
        }

        return new self($expectations);
    }

    public function expectedFor(string $rubricName): ?bool
    {
        return $this->expectations[$rubricName] ?? null;
    }
}
