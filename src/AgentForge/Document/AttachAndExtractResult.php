<?php

/**
 * Result for the M4 attach_and_extract tool surface.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\AgentForge\Document;

use OpenEMR\AgentForge\Document\Extraction\ExtractionProviderResponse;

final readonly class AttachAndExtractResult
{
    private function __construct(
        public bool $success,
        public ?DocumentId $documentId,
        public ?ExtractionProviderResponse $extraction,
        public ?ExtractionErrorCode $errorCode,
        public ?string $errorMessage,
    ) {
    }

    public static function succeeded(DocumentId $documentId, ExtractionProviderResponse $extraction): self
    {
        return new self(true, $documentId, $extraction, null, null);
    }

    public static function failed(ExtractionErrorCode $errorCode, string $errorMessage, ?DocumentId $documentId = null): self
    {
        return new self(false, $documentId, null, $errorCode, $errorMessage);
    }
}
