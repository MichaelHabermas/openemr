<?php

/**
 * OpenEMR document id value object for AgentForge document jobs.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\AgentForge\Document;

use DomainException;

final readonly class DocumentId
{
    public function __construct(public int $value)
    {
        if ($value <= 0) {
            throw new DomainException('Document id must be positive.');
        }
    }
}
