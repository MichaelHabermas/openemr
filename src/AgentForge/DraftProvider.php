<?php

/**
 * Generates a structured draft from bounded evidence.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\AgentForge;

interface DraftProvider
{
    public function draft(AgentRequest $request, EvidenceBundle $bundle): DraftResponse;
}
