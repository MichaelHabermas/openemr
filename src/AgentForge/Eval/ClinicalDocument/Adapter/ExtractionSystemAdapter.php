<?php

declare(strict_types=1);

namespace OpenEMR\AgentForge\Eval\ClinicalDocument\Adapter;

use OpenEMR\AgentForge\Eval\ClinicalDocument\Case\EvalCase;

interface ExtractionSystemAdapter
{
    public function runCase(EvalCase $case): CaseRunOutput;
}
