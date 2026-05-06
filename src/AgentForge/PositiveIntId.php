<?php

/**
 * Base class for positive-integer identity value objects.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\AgentForge;

use DomainException;

abstract readonly class PositiveIntId
{
    public function __construct(
        public int $value,
        string $label,
    ) {
        if ($value <= 0) {
            throw new DomainException("$label must be positive.");
        }
    }
}
