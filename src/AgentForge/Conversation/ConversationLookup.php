<?php

/**
 * Lookup result for server-bound conversation validation.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\AgentForge\Conversation;

final readonly class ConversationLookup
{
    public const FOUND = 'found';
    public const MISSING = 'missing';
    public const EXPIRED = 'expired';
    public const TURN_LIMIT_EXCEEDED = 'turn_limit_exceeded';

    private function __construct(
        public string $status,
        public ?ConversationState $state = null,
    ) {
    }

    public static function found(ConversationState $state): self
    {
        return new self(self::FOUND, $state);
    }

    public static function missing(): self
    {
        return new self(self::MISSING);
    }

    public static function expired(): self
    {
        return new self(self::EXPIRED);
    }

    public static function turnLimitExceeded(): self
    {
        return new self(self::TURN_LIMIT_EXCEEDED);
    }
}
