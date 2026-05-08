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

    public function safeMessage(): string
    {
        return match ($this->errorCode) {
            ExtractionErrorCode::UnsupportedDocType => 'Document type is not supported for runtime extraction.',
            ExtractionErrorCode::UnsupportedMimeType => 'Document MIME type is not supported for content normalization.',
            ExtractionErrorCode::NormalizationFailure => 'Document content normalization failed.',
            ExtractionErrorCode::SchemaValidationFailure => 'Extraction provider response failed strict schema validation.',
            default => 'Extraction provider failed before extraction completed.',
        };
    }
}
