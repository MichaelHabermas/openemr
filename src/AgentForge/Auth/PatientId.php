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

use OpenEMR\AgentForge\PositiveIntId;

final readonly class PatientId extends PositiveIntId
{
    public function __construct(int $value)
    {
        parent::__construct($value, 'Patient id');
    }
}
