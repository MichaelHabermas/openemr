<?php

/**
 * Patient authorization decision for AgentForge.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\AgentForge;

final readonly class AuthorizationDecision
{
    private function __construct(
        public bool $allowed,
        public string $reason,
    ) {
    }

    public static function allow(): self
    {
        return new self(true, 'allowed');
    }

    public static function refuse(string $reason): self
    {
        return new self(false, $reason);
    }
}
