<?php

/**
 * Test-only AgentForge handler that returns authorized chart evidence.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Tests\Isolated\AgentForge\Support;

use OpenEMR\AgentForge\Evidence\ChartEvidenceTool;
use OpenEMR\AgentForge\Evidence\ChartEvidenceToolInvoker;
use OpenEMR\AgentForge\Handlers\AgentHandler;
use OpenEMR\AgentForge\Handlers\AgentRequest;
use OpenEMR\AgentForge\Handlers\AgentResponse;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

final readonly class EvidenceAgentHandler implements AgentHandler
{
    /** @param list<ChartEvidenceTool> $tools */
    public function __construct(
        private array $tools,
        private LoggerInterface $logger = new NullLogger(),
    ) {
    }

    public function handle(AgentRequest $request): AgentResponse
    {
        $results = [];
        foreach ($this->tools as $tool) {
            $results[] = ChartEvidenceToolInvoker::collectOrFailure(
                $tool,
                $request->patientId,
                $this->logger,
            );
        }

        return AgentResponse::fromEvidence($request, $results);
    }
}
