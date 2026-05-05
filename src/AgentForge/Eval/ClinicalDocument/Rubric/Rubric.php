<?php

declare(strict_types=1);

namespace OpenEMR\AgentForge\Eval\ClinicalDocument\Rubric;

interface Rubric
{
    public function name(): string;

    public function evaluate(RubricInputs $inputs): RubricResult;
}
