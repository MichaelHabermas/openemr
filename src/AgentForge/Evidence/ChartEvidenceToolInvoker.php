<?php

/**
 * Shared collect + log + failure mapping for chart evidence tools.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\AgentForge\Evidence;

use DomainException;
use OpenEMR\AgentForge\Auth\PatientId;
use OpenEMR\AgentForge\Deadline;
use OpenEMR\AgentForge\Observability\PatientRefHasher;
use Psr\Log\LoggerInterface;
use RuntimeException;

final class ChartEvidenceToolInvoker
{
    public static function collectOrFailure(
        ChartEvidenceTool $tool,
        PatientId $patientId,
        LoggerInterface $logger,
        ?Deadline $deadline = null,
    ): EvidenceResult {
        try {
            return $tool->collect($patientId, $deadline);
        } catch (DomainException | RuntimeException $exception) {
            $logger->error(
                'AgentForge evidence tool failed unexpectedly.',
                [
                    'failure_class' => $exception::class,
                    'tool' => $tool::class,
                    'patient_ref' => PatientRefHasher::createDefault()->hash($patientId),
                ],
            );

            return EvidenceResult::failure(
                $tool->section(),
                sprintf('%s could not be checked.', $tool->section()),
            );
        }
    }
}
