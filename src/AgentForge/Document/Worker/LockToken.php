<?php

/**
 * Opaque lock token used to correlate a claimed document-processing job.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\AgentForge\Document\Worker;

use DomainException;

final readonly class LockToken
{
    public function __construct(public string $value)
    {
        if (!preg_match('/\A[a-f0-9]{64}\z/', $value)) {
            throw new DomainException('Document job lock token must be 64 lowercase hex characters.');
        }
    }

    public static function generate(): self
    {
        return new self(bin2hex(random_bytes(32)));
    }

    public function prefix(): string
    {
        return substr($this->value, 0, 8);
    }
}
