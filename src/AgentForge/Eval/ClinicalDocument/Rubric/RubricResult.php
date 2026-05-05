<?php

declare(strict_types=1);

namespace OpenEMR\AgentForge\Eval\ClinicalDocument\Rubric;

final readonly class RubricResult
{
    public function __construct(
        public string $name,
        public RubricStatus $status,
        public string $reason,
    ) {
    }
}
