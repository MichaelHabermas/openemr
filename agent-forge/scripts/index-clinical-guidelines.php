#!/usr/bin/env php
<?php

/**
 * Rebuild the AgentForge clinical guideline corpus index.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/vendor/autoload.php';

use OpenEMR\AgentForge\Cli\AgentForgeRepoPaths;
use OpenEMR\AgentForge\DefaultDatabaseExecutor;
use OpenEMR\AgentForge\Guidelines\DeterministicGuidelineEmbeddingProvider;
use OpenEMR\AgentForge\Guidelines\GuidelineCorpusIndexer;
use OpenEMR\AgentForge\Guidelines\SqlGuidelineChunkRepository;

$repo = AgentForgeRepoPaths::fromScriptsDirectory(__DIR__);
$GLOBALS['OE_SITE_DIR'] = getenv('OE_SITE_DIR') ?: $repo . '/sites/default';
$corpusDir = $repo . '/agent-forge/fixtures/clinical-guideline-corpus';
$indexer = new GuidelineCorpusIndexer(
    new SqlGuidelineChunkRepository(new DefaultDatabaseExecutor()),
    new DeterministicGuidelineEmbeddingProvider(),
    $corpusDir,
);

try {
    $count = $indexer->index();
    fwrite(STDOUT, sprintf(
        "Indexed %d clinical guideline chunks for corpus %s.\n",
        $count,
        $indexer->corpusVersion(),
    ));

    exit(0);
} catch (Throwable $throwable) {
    fwrite(STDERR, "Clinical guideline indexing failed.\n");
    fwrite(STDERR, $throwable->getMessage() . "\n");

    exit(1);
}
