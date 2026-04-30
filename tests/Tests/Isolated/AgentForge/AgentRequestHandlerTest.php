<?php

/**
 * Isolated tests for AgentForge endpoint orchestration.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Tests\Isolated\AgentForge;

use OpenEMR\AgentForge\AgentRequest;
use OpenEMR\AgentForge\AgentRequestHandler;
use OpenEMR\AgentForge\AgentRequestParser;
use OpenEMR\AgentForge\AgentRequestParserInterface;
use OpenEMR\AgentForge\AgentRequestResult;
use OpenEMR\AgentForge\ChartEvidenceTool;
use OpenEMR\AgentForge\EvidenceAgentHandler;
use OpenEMR\AgentForge\EvidenceItem;
use OpenEMR\AgentForge\EvidenceResult;
use OpenEMR\AgentForge\PatientAccessRepository;
use OpenEMR\AgentForge\PatientAuthorizationGate;
use OpenEMR\AgentForge\PatientId;
use OpenEMR\AgentForge\PlaceholderAgentHandler;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use RuntimeException;

final class AgentRequestHandlerTest extends TestCase
{
    public function testRefusesNonPostRequest(): void
    {
        $result = $this->handler()->handle('GET', [], null, null, false, false, 'request-1');

        $this->assertResult($result, 405, 'refused_method_not_post', 'AgentForge requests must use POST.');
    }

    public function testRefusesBadCsrf(): void
    {
        $result = $this->handler()->handle('POST', [], 7, 900001, true, false, 'request-1');

        $this->assertResult($result, 403, 'refused_bad_csrf', 'The request could not be verified.');
    }

    public function testRefusesMissingPatientId(): void
    {
        $result = $this->handler()->handle('POST', ['question' => 'What changed?'], 7, 900001, true, true, 'request-1');

        $this->assertResult($result, 400, 'refused_invalid_request', 'Patient id is required.');
    }

    public function testRefusesEmptyQuestion(): void
    {
        $result = $this->handler()->handle(
            'POST',
            ['patient_id' => '900001', 'question' => '   '],
            7,
            900001,
            true,
            true,
            'request-1',
        );

        $this->assertResult($result, 400, 'refused_invalid_request', 'Question is required.');
    }

    public function testRefusesMissingSessionUser(): void
    {
        $result = $this->handler()->handle('POST', $this->validPost(), null, 900001, true, true, 'request-1');

        $this->assertResult(
            $result,
            403,
            'refused_no_active_openemr_session_user_was_found',
            'No active OpenEMR session user was found.',
        );
    }

    public function testRefusesMissingPatientContext(): void
    {
        $result = $this->handler()->handle('POST', $this->validPost(), 7, null, true, true, 'request-1');

        $this->assertResult(
            $result,
            403,
            'refused_no_active_patient_chart_context_was_found',
            'No active patient chart context was found.',
        );
    }

    public function testRefusesPatientMismatch(): void
    {
        $result = $this->handler()->handle('POST', $this->validPost(), 7, 42, true, true, 'request-1');

        $this->assertResult(
            $result,
            403,
            'refused_the_requested_patient_does_not_match_the_active_chart',
            'The requested patient does not match the active chart.',
        );
    }

    public function testRefusesMissingMedicalAcl(): void
    {
        $result = $this->handler()->handle('POST', $this->validPost(), 7, 900001, false, true, 'request-1');

        $this->assertResult(
            $result,
            403,
            'refused_the_active_user_does_not_have_medical_record_access',
            'The active user does not have medical-record access.',
        );
    }

    public function testRefusesUnverifiedPatient(): void
    {
        $result = $this->handler(patientExists: false)
            ->handle('POST', $this->validPost(), 7, 900001, true, true, 'request-1');

        $this->assertResult(
            $result,
            403,
            'refused_the_requested_patient_chart_could_not_be_verified',
            'The requested patient chart could not be verified.',
        );
    }

    public function testRefusesMissingRelationship(): void
    {
        $result = $this->handler(hasRelationship: false)
            ->handle('POST', $this->validPost(), 7, 900001, true, true, 'request-1');

        $this->assertResult(
            $result,
            403,
            'refused_patient_specific_access_could_not_be_verified_for_this_user',
            'Patient-specific access could not be verified for this user.',
        );
    }

    public function testRefusesUnclearRepositoryState(): void
    {
        $result = $this->handler(repositoryThrows: true)
            ->handle('POST', $this->validPost(), 7, 900001, true, true, 'request-1');

        $this->assertResult(
            $result,
            403,
            'refused_patient_specific_access_is_unclear',
            'Patient-specific access is unclear.',
        );
    }

    public function testUnexpectedParserErrorReturnsGenericFailureAndLogsInternally(): void
    {
        $logger = new HandlerRecordingLogger();
        $parser = new class implements AgentRequestParserInterface {
            public function parse(array $input): AgentRequest
            {
                throw new RuntimeException('SQLSTATE connection internals');
            }
        };

        $result = $this->handler(parser: $parser, logger: $logger)
            ->handle('POST', $this->validPost(), 7, 900001, true, true, 'request-1');

        $this->assertResult($result, 500, 'refused_unexpected_error', 'The request could not be processed.');
        $responseJson = json_encode($result->response->toArray(), JSON_THROW_ON_ERROR);

        $this->assertStringNotContainsString('SQLSTATE', $responseJson);
        $this->assertCount(1, $logger->records);
        $this->assertSame('error', $logger->records[0]['level']);
        $this->assertSame('request-1', $logger->records[0]['context']['request_id']);
    }

    public function testAllowedRequestReachesPlaceholderHandler(): void
    {
        $result = $this->handler()->handle('POST', $this->validPost(), 7, 900001, true, true, 'request-1');

        $this->assertSame(200, $result->statusCode);
        $this->assertSame('allowed', $result->decision);
        $this->assertSame(900001, $result->logPatientId);
        $this->assertSame('ok', $result->response->status);
        $this->assertStringContainsString('patient 900001', $result->response->answer);
    }

    public function testAllowedRequestRunsEvidenceHandlerAfterAuthorization(): void
    {
        $tool = new RecordingEvidenceTool();
        $result = $this->handler(agentHandler: new EvidenceAgentHandler([$tool]))
            ->handle('POST', $this->validPost(), 7, 900001, true, true, 'request-1');

        $this->assertSame(200, $result->statusCode);
        $this->assertSame('allowed', $result->decision);
        $this->assertTrue($tool->called);
        $this->assertStringContainsString('Chart evidence checked for patient 900001.', $result->response->answer);
        $this->assertSame(['lab:procedure_result/agentforge-a1c-2026-04@2026-04-10'], $result->response->citations);
    }

    public function testAuthorizationFailureDoesNotRunEvidenceTools(): void
    {
        $tool = new RecordingEvidenceTool();
        $result = $this->handler(hasRelationship: false, agentHandler: new EvidenceAgentHandler([$tool]))
            ->handle('POST', $this->validPost(), 7, 900001, true, true, 'request-1');

        $this->assertSame(403, $result->statusCode);
        $this->assertFalse($tool->called);
    }

    public function testUnexpectedEvidenceToolFailureReturnsGenericUncheckedSectionAndLogsInternally(): void
    {
        $logger = new HandlerRecordingLogger();
        $result = $this->handler(
            agentHandler: new EvidenceAgentHandler([new ThrowingEvidenceTool()], $logger),
        )->handle('POST', $this->validPost(), 7, 900001, true, true, 'request-1');

        $responseJson = json_encode($result->response->toArray(), JSON_THROW_ON_ERROR);

        $this->assertSame(200, $result->statusCode);
        $this->assertSame(['Recent labs could not be checked.'], $result->response->missingOrUncheckedSections);
        $this->assertStringNotContainsString('SQLSTATE', $responseJson);
        $this->assertCount(1, $logger->records);
        $this->assertSame('error', $logger->records[0]['level']);
    }

    /** @return array{patient_id: string, question: string} */
    private function validPost(): array
    {
        return [
            'patient_id' => '900001',
            'question' => 'What changed since last visit?',
        ];
    }

    private function handler(
        bool $patientExists = true,
        bool $hasRelationship = true,
        bool $repositoryThrows = false,
        ?AgentRequestParserInterface $parser = null,
        ?HandlerRecordingLogger $logger = null,
        ?\OpenEMR\AgentForge\AgentHandler $agentHandler = null,
    ): AgentRequestHandler {
        return new AgentRequestHandler(
            $parser ?? new AgentRequestParser(),
            new PatientAuthorizationGate($this->repository($patientExists, $hasRelationship, $repositoryThrows)),
            $agentHandler ?? new PlaceholderAgentHandler(),
            $logger ?? new HandlerRecordingLogger(),
        );
    }

    private function repository(bool $patientExists, bool $hasRelationship, bool $throws): PatientAccessRepository
    {
        return new class ($patientExists, $hasRelationship, $throws) implements PatientAccessRepository {
            public function __construct(
                private readonly bool $patientExists,
                private readonly bool $hasRelationship,
                private readonly bool $throws,
            ) {
            }

            public function patientExists(PatientId $patientId): bool
            {
                if ($this->throws) {
                    throw new RuntimeException('database unavailable');
                }

                return $this->patientExists;
            }

            public function userHasDirectRelationship(PatientId $patientId, int $userId): bool
            {
                if ($this->throws) {
                    throw new RuntimeException('database unavailable');
                }

                return $this->hasRelationship;
            }
        };
    }

    private function assertResult(
        AgentRequestResult $result,
        int $statusCode,
        string $decision,
        string $message,
    ): void {
        $this->assertSame($statusCode, $result->statusCode);
        $this->assertSame($decision, $result->decision);
        $this->assertSame('refused', $result->response->status);
        $this->assertSame([$message], $result->response->refusalsOrWarnings);
    }
}

final class RecordingEvidenceTool implements ChartEvidenceTool
{
    public bool $called = false;

    public function section(): string
    {
        return 'Recent labs';
    }

    public function collect(PatientId $patientId): EvidenceResult
    {
        $this->called = true;

        return EvidenceResult::found(
            'Recent labs',
            [
                new EvidenceItem(
                    'lab',
                    'procedure_result',
                    'agentforge-a1c-2026-04',
                    '2026-04-10',
                    'Hemoglobin A1c',
                    '7.4 %',
                ),
            ],
        );
    }
}

final class ThrowingEvidenceTool implements ChartEvidenceTool
{
    public function section(): string
    {
        return 'Recent labs';
    }

    public function collect(PatientId $patientId): EvidenceResult
    {
        throw new RuntimeException('SQLSTATE internal chart evidence failure');
    }
}

final class HandlerRecordingLogger extends AbstractLogger
{
    /** @var list<array{level: mixed, message: string|\Stringable, context: array<string, mixed>}> */
    public array $records = [];

    /** @param array<mixed> $context */
    public function log($level, string|\Stringable $message, array $context = []): void
    {
        $this->records[] = [
            'level' => $level,
            'message' => $message,
            'context' => $this->stringKeyedContext($context),
        ];
    }

    /**
     * @param array<mixed> $context
     * @return array<string, mixed>
     */
    private function stringKeyedContext(array $context): array
    {
        $normalized = [];
        foreach ($context as $key => $value) {
            if (is_string($key)) {
                $normalized[$key] = $value;
            }
        }

        return $normalized;
    }
}
