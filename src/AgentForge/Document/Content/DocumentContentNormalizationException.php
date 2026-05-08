<?php

/**
 * Modeled content normalization failure.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\AgentForge\Document\Content;

use OpenEMR\AgentForge\Document\ExtractionErrorCode;
use RuntimeException;

final class DocumentContentNormalizationException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly ExtractionErrorCode $errorCode = ExtractionErrorCode::NormalizationFailure,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, previous: $previous);
    }
}
