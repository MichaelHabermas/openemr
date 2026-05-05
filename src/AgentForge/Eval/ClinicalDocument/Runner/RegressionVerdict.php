<?php

declare(strict_types=1);

namespace OpenEMR\AgentForge\Eval\ClinicalDocument\Runner;

enum RegressionVerdict: string
{
    case BaselineMet = 'baseline_met';
    case ThresholdViolation = 'threshold_violation';
    case RegressionExceeded = 'regression_exceeded';
    case RunnerError = 'runner_error';
}
