<?php

/**
 * Draft provider usage metadata.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\AgentForge\ResponseGeneration;

final readonly class DraftUsage
{
    public function __construct(
        public string $model,
        public int $inputTokens,
        public int $outputTokens,
        public ?float $estimatedCost,
    ) {
    }

    public static function fixture(): self
    {
        return new self('fixture-draft-provider', 0, 0, null);
    }

    public static function notRun(): self
    {
        return new self('not_run', 0, 0, null);
    }
}
