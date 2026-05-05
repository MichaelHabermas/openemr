<?php

/**
 * AgentForge document job lifecycle status.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\AgentForge\Document;

use DomainException;

enum JobStatus: string
{
    case Pending = 'pending';
    case Running = 'running';
    case Succeeded = 'succeeded';
    case Failed = 'failed';
    case Retracted = 'retracted';

    public static function fromStringOrThrow(string $raw): self
    {
        return self::tryFrom($raw) ?? throw new DomainException("Unknown job status: {$raw}");
    }
}
