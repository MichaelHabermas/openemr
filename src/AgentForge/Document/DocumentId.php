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

use OpenEMR\AgentForge\PositiveIntId;

final readonly class DocumentId extends PositiveIntId
{
    public function __construct(int $value)
    {
        parent::__construct($value, 'Document id');
    }
}
