<?php

declare(strict_types=1);

namespace OpenEMR\AgentForge\Eval\ClinicalDocument\Rubric;

use OpenEMR\AgentForge\Eval\ClinicalDocument\Adapter\CaseRunOutput;
use OpenEMR\AgentForge\Eval\ClinicalDocument\Case\EvalCase;

final readonly class RubricInputs
{
    public function __construct(
        public EvalCase $case,
        public CaseRunOutput $output,
    ) {
    }
}
