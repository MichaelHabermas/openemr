<?php

/**
 * AgentForge document worker heartbeat status.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\AgentForge\Document\Worker;

use DomainException;

enum WorkerStatus: string
{
    case Starting = 'starting';
    case Running = 'running';
    case Idle = 'idle';
    case Stopping = 'stopping';
    case Stopped = 'stopped';

    public static function fromStringOrThrow(string $raw): self
    {
        return self::tryFrom($raw) ?? throw new DomainException("Unknown worker status: {$raw}");
    }
}
