<?php

/**
 * Clinical document eval support for AgentForge.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\AgentForge\Eval\ClinicalDocument\Adapter;

final readonly class CaseRunOutput
{
    /**
     * @param array<string, mixed> $extraction
     * @param list<array<string, mixed>> $promotions
     * @param list<array<string, mixed>> $documentFacts
     * @param array<string, mixed> $retrieval
     * @param array<string, mixed> $answer
     * @param list<array<string, mixed>> $logLines
     * @param list<array<string, mixed>> $citations
     */
    public function __construct(
        public string $status,
        public array $extraction = [],
        public array $promotions = [],
        public array $documentFacts = [],
        public array $retrieval = [],
        public array $answer = [],
        public array $logLines = [],
        public array $citations = [],
        public ?string $failureReason = null,
    ) {
    }
}
