<?php

/**
 * Clinical document eval support for AgentForge.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\AgentForge\Eval\ClinicalDocument\Rubric;

final readonly class RubricResult
{
    public function __construct(
        public string $name,
        public RubricStatus $status,
        public string $reason,
        public ?float $score = null,
    ) {
    }
}
