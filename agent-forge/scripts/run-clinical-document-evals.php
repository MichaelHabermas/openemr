#!/usr/bin/env php
<?php

/**
 * Clinical document eval runner entry point for AgentForge.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/vendor/autoload.php';

use OpenEMR\AgentForge\Eval\ClinicalDocument\Cli\RunClinicalDocumentEvalsCommand;

exit((new RunClinicalDocumentEvalsCommand())->run(dirname(__DIR__, 2)));
