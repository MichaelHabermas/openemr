<?php

/**
 * Guideline retrieval boundary used by Week 2 orchestration.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\AgentForge\Guidelines;

interface GuidelineRetriever
{
    public function retrieve(string $query): GuidelineRetrievalResult;
}
