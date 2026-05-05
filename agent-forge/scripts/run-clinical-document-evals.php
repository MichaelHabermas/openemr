#!/usr/bin/env php
<?php

/**
 * Run the AgentForge clinical document eval gate.
 *
 * Exit codes:
 * 0 baseline met and thresholds satisfied
 * 1 regression drop exceeded baseline allowance
 * 2 rubric pass rate below threshold
 * 3 runner/configuration error
 */

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/vendor/autoload.php';

use OpenEMR\AgentForge\Eval\ClinicalDocument\Cli\RunClinicalDocumentEvalsCommand;

exit((new RunClinicalDocumentEvalsCommand())->run(dirname(__DIR__, 2)));
