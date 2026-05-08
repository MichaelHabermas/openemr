<?php

/**
 * Test-only AgentForge handler returning the placeholder response.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Tests\Isolated\AgentForge\Support;

use OpenEMR\AgentForge\Handlers\AgentHandler;
use OpenEMR\AgentForge\Handlers\AgentRequest;
use OpenEMR\AgentForge\Handlers\AgentResponse;

final class PlaceholderAgentHandler implements AgentHandler
{
    public function handle(AgentRequest $request): AgentResponse
    {
        return AgentResponse::placeholder($request);
    }
}
