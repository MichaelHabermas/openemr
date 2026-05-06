<?php

/**
 * Runtime failure raised by AgentForge extraction providers.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\AgentForge\Document\Extraction;

use OpenEMR\AgentForge\Document\ExtractionErrorCode;
use RuntimeException;
use Throwable;

class ExtractionProviderException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly ExtractionErrorCode $errorCode = ExtractionErrorCode::ExtractionFailure,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, previous: $previous);
    }
}
