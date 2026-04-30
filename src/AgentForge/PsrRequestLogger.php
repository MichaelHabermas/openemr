<?php

/**
 * PSR-3 AgentForge request logger.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\AgentForge;

use Psr\Log\LoggerInterface;

final readonly class PsrRequestLogger implements RequestLogger
{
    public function __construct(private LoggerInterface $logger)
    {
    }

    public function record(RequestLog $entry): void
    {
        $this->logger->warning('agent_forge_request', $entry->toContext());
    }
}
