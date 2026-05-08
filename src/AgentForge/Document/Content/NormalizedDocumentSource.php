<?php

/**
 * Source metadata retained during content normalization.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\AgentForge\Document\Content;

use DomainException;
use OpenEMR\AgentForge\Document\DocumentType;
use OpenEMR\AgentForge\Document\Worker\DocumentLoadResult;

final readonly class NormalizedDocumentSource
{
    public function __construct(
        public string $name,
        public string $mimeType,
        public string $sha256,
        public int $byteLength,
        public DocumentType $documentType,
    ) {
        if (trim($mimeType) === '') {
            throw new DomainException('Normalized document source MIME type must not be empty.');
        }
        if (!preg_match('/^[a-f0-9]{64}$/', $sha256)) {
            throw new DomainException('Normalized document source SHA-256 must be lowercase hexadecimal.');
        }
        if ($byteLength < 1) {
            throw new DomainException('Normalized document source byte length must be positive.');
        }
    }

    public static function fromLoadResult(DocumentLoadResult $document, DocumentType $documentType): self
    {
        return new self(
            $document->name,
            $document->mimeType,
            hash('sha256', $document->bytes),
            $document->byteCount,
            $documentType,
        );
    }
}
