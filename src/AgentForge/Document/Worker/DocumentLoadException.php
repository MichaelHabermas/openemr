<?php

/**
 * Modeled failure while loading an OpenEMR source document.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\AgentForge\Document\Worker;

use RuntimeException;
use Throwable;

final class DocumentLoadException extends RuntimeException
{
    private function __construct(public readonly string $errorCode, ?Throwable $previous = null)
    {
        parent::__construct($errorCode, 0, $previous);
    }

    public static function missing(): self
    {
        return new self('source_document_missing');
    }

    public static function sourceDeleted(): self
    {
        return new self('source_document_deleted');
    }

    public static function expired(): self
    {
        return new self('source_document_expired');
    }

    public static function unreadable(Throwable $previous): self
    {
        return new self('source_document_unreadable', $previous);
    }
}
