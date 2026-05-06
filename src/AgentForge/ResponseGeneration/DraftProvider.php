<?php

/**
 * Generates a structured draft from bounded evidence.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\AgentForge\ResponseGeneration;

use OpenEMR\AgentForge\Deadline;
use OpenEMR\AgentForge\Evidence\EvidenceBundle;

interface DraftProvider
{
    public function draft(DraftRequest $request, EvidenceBundle $bundle, Deadline $deadline): DraftResponse;
}
