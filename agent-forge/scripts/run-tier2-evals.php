#!/usr/bin/env php
<?php

/**
 * Run AgentForge Tier 2 (live LLM) evals.
 *
 * Requires AGENTFORGE_OPENAI_API_KEY (or OPENAI_API_KEY) or
 * AGENTFORGE_ANTHROPIC_API_KEY (or ANTHROPIC_API_KEY). The runner refuses to
 * proceed with the fixture provider so that a model-off pass is never reported
 * as live-provider proof.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/vendor/autoload.php';
require_once __DIR__ . '/lib/tier2-eval-runner.php';

exit(agentforge_tier2_main());
