<?php

/**
 * Isolated tests for AgentForge request lifecycle logging.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Tests\Isolated\AgentForge;

use OpenEMR\AgentForge\Auth\PatientAccessRepository;
use OpenEMR\AgentForge\Auth\PatientAuthorizationGate;
use OpenEMR\AgentForge\Auth\PatientId;
use OpenEMR\AgentForge\Handlers\AgentRequestHandler;
use OpenEMR\AgentForge\Handlers\AgentRequestLifecycle;
use OpenEMR\AgentForge\Handlers\AgentRequestParser;
use OpenEMR\AgentForge\Handlers\PlaceholderAgentHandler;
use OpenEMR\AgentForge\Observability\RequestLog;
use OpenEMR\AgentForge\Observability\RequestLogger;
use PHPUnit\Framework\TestCase;

final class AgentRequestLifecycleTest extends TestCase
{
    public function testAllowedRequestIsLoggedExactlyOnce(): void
    {
        $logger = new LifecycleRecordingRequestLogger();
        $result = $this->lifecycle($logger)->handle(
            'POST',
            ['patient_id' => '900001', 'question' => 'What changed?'],
            7,
            900001,
            true,
            true,
            'request-1',
        );

        $this->assertSame('allowed', $result->decision);
        $this->assertCount(1, $logger->entries);
        $this->assertSame('allowed', $logger->entries[0]->decision);
        $this->assertSame(900001, $logger->entries[0]->patientId);
        $this->assertNotNull($logger->entries[0]->conversationId);
    }

    public function testRefusedRequestIsLoggedExactlyOnce(): void
    {
        $logger = new LifecycleRecordingRequestLogger();
        $result = $this->lifecycle($logger)->handle(
            'POST',
            ['patient_id' => '900001', 'question' => 'What changed?'],
            7,
            900001,
            true,
            false,
            'request-1',
        );

        $this->assertSame('refused_bad_csrf', $result->decision);
        $this->assertCount(1, $logger->entries);
        $this->assertSame('refused_bad_csrf', $logger->entries[0]->decision);
    }

    private function lifecycle(LifecycleRecordingRequestLogger $logger): AgentRequestLifecycle
    {
        return new AgentRequestLifecycle(
            new AgentRequestHandler(
                new AgentRequestParser(),
                new PatientAuthorizationGate(new LifecycleAllowingPatientAccessRepository()),
                new PlaceholderAgentHandler(),
            ),
            $logger,
        );
    }
}

final class LifecycleRecordingRequestLogger implements RequestLogger
{
    /** @var list<RequestLog> */
    public array $entries = [];

    public function record(RequestLog $entry): void
    {
        $this->entries[] = $entry;
    }
}

final readonly class LifecycleAllowingPatientAccessRepository implements PatientAccessRepository
{
    public function patientExists(PatientId $patientId): bool
    {
        return $patientId->value === 900001;
    }

    public function userHasDirectRelationship(PatientId $patientId, int $userId): bool
    {
        return $patientId->value === 900001 && $userId === 7;
    }
}
