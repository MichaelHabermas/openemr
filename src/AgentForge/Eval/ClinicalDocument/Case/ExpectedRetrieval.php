<?php

declare(strict_types=1);

namespace OpenEMR\AgentForge\Eval\ClinicalDocument\Case;

final readonly class ExpectedRetrieval
{
    public function __construct(
        public bool $guidelineRetrievalRequired,
        public int $minGuidelineChunks,
        public bool $outOfCorpus = false,
    ) {
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        $minChunks = $data['min_guideline_chunks'] ?? 0;
        return new self(
            (bool) ($data['guideline_retrieval_required'] ?? false),
            is_int($minChunks) ? $minChunks : 0,
            (bool) ($data['out_of_corpus'] ?? false),
        );
    }
}
