<?php

/**
 * Isolated tests for AgentForge verified handler orchestration.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Tests\Isolated\AgentForge;

use DomainException;
use OpenEMR\AgentForge\AgentForgeClock;
use OpenEMR\AgentForge\Auth\PatientId;
use OpenEMR\AgentForge\Deadline;
use OpenEMR\AgentForge\Evidence\ChartEvidenceTool;
use OpenEMR\AgentForge\Evidence\EvidenceBundle;
use OpenEMR\AgentForge\Evidence\EvidenceItem;
use OpenEMR\AgentForge\Evidence\EvidenceResult;
use OpenEMR\AgentForge\Handlers\AgentQuestion;
use OpenEMR\AgentForge\Handlers\AgentRequest;
use OpenEMR\AgentForge\Handlers\VerifiedAgentHandler;
use OpenEMR\AgentForge\ResponseGeneration\DraftClaim;
use OpenEMR\AgentForge\ResponseGeneration\DraftProvider;
use OpenEMR\AgentForge\ResponseGeneration\DraftProviderException;
use OpenEMR\AgentForge\ResponseGeneration\DraftResponse;
use OpenEMR\AgentForge\ResponseGeneration\DraftSentence;
use OpenEMR\AgentForge\ResponseGeneration\DraftUsage;
use OpenEMR\AgentForge\ResponseGeneration\FixtureDraftProvider;
use OpenEMR\AgentForge\Verification\DraftVerifier;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use RuntimeException;

final class VerifiedAgentHandlerTest extends TestCase
{
    public function testSafeChartQuestionReturnsVerifiedCitedOutput(): void
    {
        $response = (new VerifiedAgentHandler(
            [new VerifiedRecordingEvidenceTool()],
            new FixtureDraftProvider(),
            new DraftVerifier(),
        ))->handle($this->request('Show me recent A1c.'));

        $this->assertSame('ok', $response->status);
        $this->assertStringContainsString('Hemoglobin A1c: 7.4 %', $response->answer);
        $this->assertSame(['lab:procedure_result/agentforge-a1c-2026-04@2026-04-10'], $response->citations);
    }

    public function testSafeChartQuestionCapturesFixtureUsageAndVerificationTelemetry(): void
    {
        $handler = new VerifiedAgentHandler(
            [new VerifiedRecordingEvidenceTool()],
            new FixtureDraftProvider(),
            new DraftVerifier(),
        );

        $handler->handle($this->request('Show me recent A1c.'));
        $telemetry = $handler->lastTelemetry();

        $this->assertNotNull($telemetry);
        $this->assertSame('lab', $telemetry->questionType);
        $this->assertSame(['Recent labs'], $telemetry->toolsCalled);
        $this->assertSame(
            ['Demographics', 'Active problems', 'Active medications', 'Recent notes and last plan'],
            $telemetry->skippedChartSections,
        );
        $this->assertSame(['lab:procedure_result/agentforge-a1c-2026-04@2026-04-10'], $telemetry->sourceIds);
        $this->assertSame('fixture-draft-provider', $telemetry->model);
        $this->assertSame(0, $telemetry->inputTokens);
        $this->assertSame(0, $telemetry->outputTokens);
        $this->assertNull($telemetry->estimatedCost);
        $this->assertNull($telemetry->failureReason);
        $this->assertSame('passed', $telemetry->verifierResult);
    }

    public function testAdviceRequestIsRefusedBeforeEvidenceDrafting(): void
    {
        $tool = new VerifiedRecordingEvidenceTool();
        $response = (new VerifiedAgentHandler(
            [$tool],
            new FixtureDraftProvider(),
            new DraftVerifier(),
        ))->handle($this->request('What dose should I prescribe?'));

        $this->assertSame('refused', $response->status);
        $this->assertFalse($tool->called);
        $this->assertSame(
            'Clinical Co-Pilot can summarize chart facts, but cannot provide diagnosis, treatment, dosing, medication-change advice, or note drafting.',
            $response->refusalsOrWarnings[0],
        );
    }

    public function testAdviceRefusalCapturesNoModelNoToolTelemetry(): void
    {
        $handler = new VerifiedAgentHandler(
            [new VerifiedRecordingEvidenceTool()],
            new FixtureDraftProvider(),
            new DraftVerifier(),
        );

        $handler->handle($this->request('What dose should I prescribe?'));
        $telemetry = $handler->lastTelemetry();

        $this->assertNotNull($telemetry);
        $this->assertSame('clinical_advice_refusal', $telemetry->questionType);
        $this->assertSame([], $telemetry->toolsCalled);
        $this->assertSame([], $telemetry->sourceIds);
        $this->assertSame(
            ['Demographics', 'Active problems', 'Active medications', 'Recent labs', 'Recent notes and last plan'],
            $telemetry->skippedChartSections,
        );
        $this->assertSame('not_run', $telemetry->model);
        $this->assertSame('clinical_advice_refusal', $telemetry->failureReason);
        $this->assertSame('not_run', $telemetry->verifierResult);
    }

    public function testUnmatchedQuestionFallsBackToVisitBriefingAndCollectsEvidence(): void
    {
        $tool = new VerifiedRecordingEvidenceTool();
        $handler = new VerifiedAgentHandler(
            [$tool],
            new FixtureDraftProvider(),
            new DraftVerifier(),
        );

        $response = $handler->handle($this->request('Tell me about this patient.'));
        $telemetry = $handler->lastTelemetry();

        $this->assertSame('ok', $response->status);
        $this->assertTrue($tool->called);
        $this->assertNotNull($telemetry);
        $this->assertSame('visit_briefing', $telemetry->questionType);
        $this->assertNull($telemetry->failureReason);
        $this->assertContains('Recent labs', $telemetry->toolsCalled);
    }

    public function testToolFailureIsVisibleWithoutLeakingInternalError(): void
    {
        $logger = new VerifiedRecordingLogger();
        $response = (new VerifiedAgentHandler(
            [new VerifiedThrowingEvidenceTool()],
            new FixtureDraftProvider(),
            new DraftVerifier(),
            $logger,
        ))->handle($this->request('Show me recent labs.'));

        $json = json_encode($response->toArray(), JSON_THROW_ON_ERROR);

        $this->assertSame('ok', $response->status);
        $this->assertSame(['Recent labs could not be checked.'], $response->missingOrUncheckedSections);
        $this->assertStringContainsString('Recent labs could not be checked.', $response->answer);
        $this->assertStringNotContainsString('SQLSTATE', $json);
        $this->assertCount(1, $logger->records);
    }

    public function testMissingLastPlanIsVisibleThroughVerifiedResponse(): void
    {
        $response = (new VerifiedAgentHandler(
            [new VerifiedMissingLastPlanEvidenceTool()],
            new FixtureDraftProvider(),
            new DraftVerifier(),
        ))->handle($this->request('What was the last plan documented for Alex?'));

        $this->assertSame('ok', $response->status);
        $this->assertSame(['Recent notes and last plan not found in the chart.'], $response->missingOrUncheckedSections);
        $this->assertStringContainsString('Recent notes and last plan not found in the chart.', $response->answer);
    }

    public function testDeadlineStopsLaterToolsAndSurfacesVisibleWarning(): void
    {
        // Ticks consumed in collector: startMs, timer.start (per tool), timer.stop (per tool), deadlineExceeded check.
        $clock = new VerifiedManualClock([0, 0, 50, 51]);
        $secondTool = new VerifiedRecordingEvidenceTool();
        $response = (new VerifiedAgentHandler(
            [
                new VerifiedRecordingEvidenceTool(),
                $secondTool,
            ],
            new FixtureDraftProvider(),
            new DraftVerifier(),
            clock: $clock,
            deadlineMs: 10,
        ))->handle($this->request('Show me recent labs.'));

        $this->assertSame('ok', $response->status);
        $this->assertFalse($secondTool->called);
        $this->assertStringContainsString('Hemoglobin A1c: 7.4 %', $response->answer);
        $this->assertContains(
            'Some chart sections could not be checked before the deadline.',
            $response->missingOrUncheckedSections,
        );
        $this->assertStringContainsString(
            'Some chart sections could not be checked before the deadline.',
            $response->answer,
        );
    }

    public function testMalformedDraftRetriesOnceThenSucceeds(): void
    {
        $provider = new RetryThenFixtureDraftProvider();
        $response = (new VerifiedAgentHandler(
            [new VerifiedRecordingEvidenceTool()],
            $provider,
            new DraftVerifier(),
        ))->handle($this->request('Show me recent labs.'));

        $this->assertSame('ok', $response->status);
        $this->assertSame(2, $provider->calls);
        $this->assertStringContainsString('Hemoglobin A1c: 7.4 %', $response->answer);
    }

    public function testMalformedDraftFailsClearlyAfterRetry(): void
    {
        $logger = new VerifiedRecordingLogger();
        $response = (new VerifiedAgentHandler(
            [new VerifiedRecordingEvidenceTool()],
            new AlwaysMalformedDraftProvider(),
            new DraftVerifier(),
            $logger,
        ))->handle($this->request('Show me recent labs.'));

        $json = json_encode($response->toArray(), JSON_THROW_ON_ERROR);

        $this->assertSame('refused', $response->status);
        $this->assertSame(['The request could not be processed.'], $response->refusalsOrWarnings);
        $this->assertStringNotContainsString('malformed internals', $json);
        $this->assertCount(1, $logger->records);
    }

    public function testDraftProviderTransportFailureIsVisibleAndSanitized(): void
    {
        $logger = new VerifiedRecordingLogger();
        $handler = new VerifiedAgentHandler(
            [new VerifiedRecordingEvidenceTool()],
            new UnavailableDraftProvider(),
            new DraftVerifier(),
            $logger,
        );

        $response = $handler->handle($this->request('Show me recent labs.'));
        $telemetry = $handler->lastTelemetry();
        $json = json_encode($response->toArray(), JSON_THROW_ON_ERROR);

        $this->assertSame('refused', $response->status);
        $this->assertSame(['The model draft provider could not be reached. Please try again.'], $response->refusalsOrWarnings);
        $this->assertStringNotContainsString('cURL timeout internals', $json);
        $this->assertNotNull($telemetry);
        $this->assertSame('draft_provider_unavailable', $telemetry->failureReason);
        $this->assertSame('not_run', $telemetry->verifierResult);
        $this->assertCount(1, $logger->records);
    }

    public function testUnverifiableDraftIsBlocked(): void
    {
        $handler = new VerifiedAgentHandler(
            [new VerifiedRecordingEvidenceTool()],
            new FabricatingDraftProvider(),
            new DraftVerifier(),
        );

        $response = $handler->handle($this->request('Show me recent labs.'));
        $telemetry = $handler->lastTelemetry();

        $this->assertSame('refused', $response->status);
        $this->assertSame(['The draft answer could not be verified.'], $response->refusalsOrWarnings);
        if ($telemetry === null) {
            $this->fail('Expected failed verification telemetry.');
        }
        $this->assertSame('failed', $telemetry->verifierResult);
        $this->assertSame('verification_failed', $telemetry->failureReason);
    }

    private function request(string $question): AgentRequest
    {
        return new AgentRequest(new PatientId(900001), new AgentQuestion($question));
    }
}

final class VerifiedRecordingEvidenceTool implements ChartEvidenceTool
{
    public bool $called = false;

    public function section(): string
    {
        return 'Recent labs';
    }

    public function collect(PatientId $patientId): EvidenceResult
    {
        $this->called = true;

        return EvidenceResult::found('Recent labs', [
            new EvidenceItem(
                'lab',
                'procedure_result',
                'agentforge-a1c-2026-04',
                '2026-04-10',
                'Hemoglobin A1c',
                '7.4 %',
            ),
        ]);
    }
}

final class VerifiedThrowingEvidenceTool implements ChartEvidenceTool
{
    public function section(): string
    {
        return 'Recent labs';
    }

    public function collect(PatientId $patientId): EvidenceResult
    {
        throw new RuntimeException('SQLSTATE private database internals');
    }
}

final class VerifiedMissingLastPlanEvidenceTool implements ChartEvidenceTool
{
    public function section(): string
    {
        return 'Recent notes and last plan';
    }

    public function collect(PatientId $patientId): EvidenceResult
    {
        return EvidenceResult::missing(
            'Recent notes and last plan',
            'Recent notes and last plan not found in the chart.',
        );
    }
}

final class RetryThenFixtureDraftProvider implements DraftProvider
{
    public int $calls = 0;

    public function draft(AgentRequest $request, EvidenceBundle $bundle, Deadline $deadline): DraftResponse
    {
        ++$this->calls;
        if ($this->calls === 1) {
            throw new DomainException('malformed draft');
        }

        return (new FixtureDraftProvider())->draft($request, $bundle, $deadline);
    }
}

final class AlwaysMalformedDraftProvider implements DraftProvider
{
    public function draft(AgentRequest $request, EvidenceBundle $bundle, Deadline $deadline): DraftResponse
    {
        throw new DomainException('malformed internals');
    }
}

final class UnavailableDraftProvider implements DraftProvider
{
    public function draft(AgentRequest $request, EvidenceBundle $bundle, Deadline $deadline): DraftResponse
    {
        throw new DraftProviderException('cURL timeout internals');
    }
}

final class FabricatingDraftProvider implements DraftProvider
{
    public function draft(AgentRequest $request, EvidenceBundle $bundle, Deadline $deadline): DraftResponse
    {
        return new DraftResponse(
            [new DraftSentence('s1', 'Hemoglobin A1c: 8.2 %')],
            [
                new DraftClaim(
                    'Hemoglobin A1c: 8.2 %',
                    DraftClaim::TYPE_PATIENT_FACT,
                    ['lab:procedure_result/agentforge-a1c-2026-04@2026-04-10'],
                    's1',
                ),
            ],
            [],
            [],
            DraftUsage::fixture(),
        );
    }
}

final class VerifiedRecordingLogger extends AbstractLogger
{
    /** @var list<array{level: mixed, message: string|\Stringable, context: array<mixed>}> */
    public array $records = [];

    /** @param array<mixed> $context */
    public function log($level, string|\Stringable $message, array $context = []): void
    {
        $this->records[] = [
            'level' => $level,
            'message' => $message,
            'context' => $context,
        ];
    }
}

final class VerifiedManualClock implements AgentForgeClock
{
    /** @param list<int> $ticks */
    public function __construct(private array $ticks)
    {
    }

    public function nowMs(): int
    {
        if ($this->ticks === []) {
            return 0;
        }

        return array_shift($this->ticks);
    }
}
