<?php

declare(strict_types=1);

namespace OpenEMR\AgentForge\Eval\ClinicalDocument\Adapter;

use OpenEMR\AgentForge\Eval\ClinicalDocument\Case\EvalCase;

final class NotImplementedAdapter implements ExtractionSystemAdapter
{
    public function runCase(EvalCase $case): CaseRunOutput
    {
        return new CaseRunOutput(
            'not_implemented',
            failureReason: 'Clinical document implementation is not connected to the eval adapter yet.',
        );
    }
}
