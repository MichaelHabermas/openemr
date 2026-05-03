<?php

/**
 * One eval case row after normalization for reporting.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\AgentForge\Reporting;

final readonly class NormalizedEvalCaseRow
{
    public function __construct(
        public string $id,
        public bool $passed,
        public string $detail,
    ) {
    }
}
