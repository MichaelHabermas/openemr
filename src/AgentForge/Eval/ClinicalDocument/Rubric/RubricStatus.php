<?php

declare(strict_types=1);

namespace OpenEMR\AgentForge\Eval\ClinicalDocument\Rubric;

enum RubricStatus: string
{
    case Pass = 'pass';
    case Fail = 'fail';
    case NotApplicable = 'not_applicable';
}
