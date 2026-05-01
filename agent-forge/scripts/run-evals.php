#!/usr/bin/env php
<?php

/**
 * Run deterministic in-process AgentForge evals.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/vendor/autoload.php';
require_once __DIR__ . '/lib/eval-runner-functions.php';

exit(agentforge_eval_main());
