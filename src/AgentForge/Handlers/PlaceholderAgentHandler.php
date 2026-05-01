<?php

/**
 * Non-model AgentForge handler used by Epic 4.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\AgentForge\Handlers;

final class PlaceholderAgentHandler implements AgentHandler
{
    public function handle(AgentRequest $request): AgentResponse
    {
        return AgentResponse::placeholder($request);
    }
}
