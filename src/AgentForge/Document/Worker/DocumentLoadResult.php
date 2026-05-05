<?php

/**
 * Loaded OpenEMR document payload for worker processing.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\AgentForge\Document\Worker;

use InvalidArgumentException;

final readonly class DocumentLoadResult
{
    public int $byteCount;

    public function __construct(
        public string $bytes,
        public string $mimeType,
        public string $name,
    ) {
        if ($bytes === '') {
            throw new InvalidArgumentException('Document bytes must not be empty.');
        }

        $this->byteCount = strlen($bytes);
    }
}
