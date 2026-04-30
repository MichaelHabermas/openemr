<?php

/**
 * Handles an authorized AgentForge request.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\AgentForge;

interface AgentHandler
{
    public function handle(AgentRequest $request): AgentResponse;
}
