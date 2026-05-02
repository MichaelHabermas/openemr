<?php

/**
 * Server-owned AgentForge conversation identifier.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\AgentForge\Conversation;

use DomainException;

final readonly class ConversationId
{
    private const PATTERN = '/\A[0-9a-f]{32}\z/';

    public function __construct(public string $value)
    {
        if (!preg_match(self::PATTERN, $value)) {
            throw new DomainException('Conversation id is invalid.');
        }
    }

    public static function generate(): self
    {
        return new self(bin2hex(random_bytes(16)));
    }
}
