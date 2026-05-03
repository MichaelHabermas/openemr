<?php

/**
 * Renders a normalized eval run for humans (Markdown, HTML, etc.).
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\AgentForge\Reporting;

interface EvalSummaryRendererInterface
{
    public function render(NormalizedEvalRun $run): string;
}
