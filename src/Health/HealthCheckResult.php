<?php

/**
 * HealthCheckResult - Value object representing the result of a health check
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    Michael A. Smith <michael@opencoreemr.com>
 * @copyright Copyright (c) 2025 OpenCoreEMR Inc <https://opencoreemr.com/>
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

namespace OpenEMR\Health;

final readonly class HealthCheckResult
{
    /**
     * @param array<string, mixed> $details
     */
    public function __construct(
        public string $name,
        public bool $healthy,
        public ?string $message = null,
        public array $details = [],
    ) {
    }

    public function toArray(): array
    {
        $result = [
            'healthy' => $this->healthy,
        ];

        if ($this->message !== null) {
            $result['message'] = $this->message;
        }

        if ($this->details !== []) {
            $result['details'] = $this->details;
        }

        return $result;
    }
}
