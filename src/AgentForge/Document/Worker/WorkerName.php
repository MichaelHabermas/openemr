<?php

/**
 * AgentForge document worker names.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\AgentForge\Document\Worker;

use DomainException;

enum WorkerName: string
{
    case Supervisor = 'supervisor';
    case IntakeExtractor = 'intake-extractor';
    case EvidenceRetriever = 'evidence-retriever';

    public static function fromStringOrThrow(string $raw): self
    {
        return self::tryFrom($raw) ?? throw new DomainException("Unknown worker name: {$raw}");
    }
}
