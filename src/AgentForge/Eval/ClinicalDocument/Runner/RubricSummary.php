<?php

/**
 * Clinical document eval support for AgentForge.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\AgentForge\Eval\ClinicalDocument\Runner;

final readonly class RubricSummary
{
    public function __construct(
        public string $name,
        public int $passed,
        public int $failed,
        public int $notApplicable,
        public float $passRate,
    ) {
    }
}
