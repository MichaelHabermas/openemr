<?php

/**
 * Result returned by a document job processor.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\AgentForge\Document\Worker;

use InvalidArgumentException;
use OpenEMR\AgentForge\Document\JobStatus;

final readonly class ProcessingResult
{
    private function __construct(
        public JobStatus $terminalStatus,
        public ?string $errorCode,
        public ?string $errorMessage,
    ) {
    }

    public static function succeeded(): self
    {
        return new self(JobStatus::Succeeded, null, null);
    }

    public static function failed(string $errorCode, string $errorMessage): self
    {
        if ($errorCode === '') {
            throw new InvalidArgumentException('Failed processing results require an error code.');
        }

        return new self(JobStatus::Failed, $errorCode, $errorMessage);
    }

    public function failedJob(): bool
    {
        return $this->terminalStatus === JobStatus::Failed;
    }
}
