<?php

/**
 * Non-model AgentForge handler that returns authorized chart evidence.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\AgentForge\Handlers;

use DomainException;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use RuntimeException;
use OpenEMR\AgentForge\Evidence\ChartEvidenceTool;
use OpenEMR\AgentForge\Evidence\EvidenceResult;

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
            try {
                $results[] = $tool->collect($request->patientId);
            } catch (DomainException | RuntimeException $exception) {
                $this->logger->error(
                    'AgentForge evidence tool failed unexpectedly.',
                    [
                        'exception' => $exception,
                        'tool' => $tool::class,
                        'patient_id' => $request->patientId->value,
                    ],
                );
                $results[] = EvidenceResult::failure(
                    $tool->section(),
                    sprintf('%s could not be checked.', $tool->section()),
                );
            }
        }

        return AgentResponse::fromEvidence($request, $results);
    }
}
