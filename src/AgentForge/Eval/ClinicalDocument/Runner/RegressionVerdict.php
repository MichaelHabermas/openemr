<?php

/**
 * Clinical document eval support for AgentForge.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\AgentForge\Eval\ClinicalDocument\Runner;

enum RegressionVerdict: string
{
    case BaselineMet = 'baseline_met';
    case ThresholdViolation = 'threshold_violation';
    case RegressionExceeded = 'regression_exceeded';
    case RunnerError = 'runner_error';
}
