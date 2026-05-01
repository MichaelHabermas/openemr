<?php

/**
 * OpenEMR patient id value object for AgentForge requests.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\AgentForge\Auth;

use DomainException;

final readonly class PatientId
{
    public function __construct(public int $value)
    {
        if ($value <= 0) {
            throw new DomainException('Patient id must be positive.');
        }
    }
}
