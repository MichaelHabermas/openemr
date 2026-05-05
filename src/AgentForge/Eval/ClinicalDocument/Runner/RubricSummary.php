<?php

declare(strict_types=1);

namespace OpenEMR\AgentForge\Eval\ClinicalDocument\Runner;

final readonly class RubricSummary
{
    public function __construct(
        public string $name,
        public int $passed,
        public int $failed,
        public int $notApplicable,
        public float $passRate,
    ) {
    }
}
