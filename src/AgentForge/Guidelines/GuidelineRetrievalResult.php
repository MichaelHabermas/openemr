<?php

/**
 * Guideline retrieval outcome.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\AgentForge\Guidelines;

final readonly class GuidelineRetrievalResult
{
    /**
     * @param list<GuidelineSearchCandidate> $candidates
     */
    public function __construct(
        public string $status,
        public array $candidates,
        public ?string $rerankerUsed,
        public float $threshold,
        public ?RetrievalMergeTelemetry $mergeTelemetry = null,
    ) {
    }

    public function found(): bool
    {
        return $this->status === 'found' && $this->candidates !== [];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function toArray(): array
    {
        return array_map(
            static fn (GuidelineSearchCandidate $candidate): array => $candidate->toArray(),
            $this->candidates,
        );
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function citations(): array
    {
        return array_map(
            static fn (GuidelineSearchCandidate $candidate): array => $candidate->chunk->citationArray(),
            $this->candidates,
        );
    }
}
