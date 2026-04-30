<?php

/**
 * Draft provider selection for AgentForge.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\AgentForge;

use DomainException;

final readonly class DraftProviderConfig
{
    public const MODE_FIXTURE = 'fixture';
    public const MODE_DISABLED = 'disabled';

    public function __construct(public string $mode = self::MODE_FIXTURE)
    {
        if ($this->mode === '') {
            throw new DomainException('Draft provider mode is required.');
        }
    }

    public static function fixture(): self
    {
        return new self(self::MODE_FIXTURE);
    }
}
