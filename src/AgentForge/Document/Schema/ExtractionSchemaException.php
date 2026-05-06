<?php

/**
 * Path-aware validation error for AgentForge document extraction schemas.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\AgentForge\Document\Schema;

use InvalidArgumentException;

final class ExtractionSchemaException extends InvalidArgumentException
{
    public function __construct(
        public readonly string $fieldPath,
        string $message,
    ) {
        parent::__construct("{$fieldPath}: {$message}");
    }
}
