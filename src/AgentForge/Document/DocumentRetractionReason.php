<?php

/**
 * Reasons an AgentForge document job lineage can be retracted.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\AgentForge\Document;

use DomainException;

enum DocumentRetractionReason: string
{
    case SourceDocumentDeleted = 'source_document_deleted';

    public static function fromStringOrThrow(string $raw): self
    {
        return self::tryFrom($raw) ?? throw new DomainException("Unknown document retraction reason: {$raw}");
    }
}
