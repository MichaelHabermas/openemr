#!/usr/bin/env php
<?php

/**
 * AgentForge clinical document processing worker.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

use OpenEMR\AgentForge\Document\Worker\ProcessDocumentJobsCommand;

ini_set('display_errors', '0');
ini_set('log_errors', '1');
error_reporting(E_ALL & ~E_NOTICE & ~E_WARNING & ~E_DEPRECATED & ~E_USER_NOTICE & ~E_USER_WARNING & ~E_USER_DEPRECATED);

if (PHP_SAPI !== 'cli') {
    echo "Only php cli can execute the AgentForge document worker.\n";
    exit(1);
}

$repoRoot = dirname(__DIR__, 2);
require_once $repoRoot . '/vendor/autoload.php';

if (!in_array('--help', $_SERVER['argv'], true) && !in_array('-h', $_SERVER['argv'], true)) {
    $site = 'default';
    foreach ($_SERVER['argv'] as $index => $arg) {
        if (str_starts_with($arg, '--site=')) {
            $site = substr($arg, strlen('--site='));
            unset($_SERVER['argv'][$index]);
        }
    }

    $_SERVER['argv'] = array_values($_SERVER['argv']);
    $_GET['site'] = $site === '' ? 'default' : $site;
    $ignoreAuth = true;
    $sessionAllowWrite = true;
    require_once $repoRoot . '/interface/globals.php';
    ini_set('display_errors', '0');
    ini_set('log_errors', '1');
    error_reporting(E_ALL & ~E_NOTICE & ~E_WARNING & ~E_DEPRECATED & ~E_USER_NOTICE & ~E_USER_WARNING & ~E_USER_DEPRECATED);
}

exit(ProcessDocumentJobsCommand::main($_SERVER['argv']));
