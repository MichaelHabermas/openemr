#!/usr/bin/env php
<?php

/**
 * Run the AgentForge Week 2 clinical-document deployed smoke proof.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/vendor/autoload.php';
require_once __DIR__ . '/lib/clinical-document-deployed-smoke-runner.php';

exit(agentforge_clinical_smoke_main());
