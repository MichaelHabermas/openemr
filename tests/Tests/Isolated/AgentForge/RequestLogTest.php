<?php

/**
 * Isolated tests for AgentForge request logging.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Tests\Isolated\AgentForge;

use DateTimeImmutable;
use OpenEMR\AgentForge\Observability\AgentTelemetry;
use OpenEMR\AgentForge\Observability\PsrRequestLogger;
use OpenEMR\AgentForge\Observability\RequestLog;
use OpenEMR\AgentForge\Observability\SensitiveLogPolicy;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;

final class RequestLogTest extends TestCase
{
    public function testRequestLogContextIsPhiMinimizedSensitiveAuditMetadata(): void
    {
        $entry = new RequestLog(
            requestId: '7aa9ef18-3227-4c43-9e5e-b8ae3fb8bbcc',
            userId: 7,
            patientId: 900001,
            decision: 'refused_patient_mismatch',
            latencyMs: 4,
            timestamp: new DateTimeImmutable('2026-04-30T12:00:00+00:00'),
            conversationId: '0123456789abcdef0123456789abcdef',
        );

        $context = $entry->toContext();

        $this->assertSame('7aa9ef18-3227-4c43-9e5e-b8ae3fb8bbcc', $context['request_id']);
        $this->assertSame(7, $context['user_id']);
        $this->assertArrayNotHasKey('patient_id', $context);
        $this->assertIsString($context['patient_ref']);
        $this->assertSame('refused_patient_mismatch', $context['decision']);
        $this->assertGreaterThanOrEqual(0, $context['latency_ms']);
        $this->assertSame('2026-04-30T12:00:00+00:00', $context['timestamp']);
        $this->assertSame('0123456789abcdef0123456789abcdef', $context['conversation_id']);
        $this->assertSame('not_classified', $context['question_type']);
        $this->assertSame([], $context['tools_called']);
        $this->assertSame([], $context['source_ids']);
        $this->assertSame('not_run', $context['model']);
        $this->assertSame(0, $context['input_tokens']);
        $this->assertSame(0, $context['output_tokens']);
        $this->assertNull($context['estimated_cost']);
        $this->assertSame('refused_patient_mismatch', $context['failure_reason']);
        $this->assertSame('not_run', $context['verifier_result']);
        $this->assertArrayNotHasKey('question', $context);
        $this->assertArrayNotHasKey('answer', $context);
        $this->assertArrayNotHasKey('patient_name', $context);
    }

    public function testRequestLogContextIncludesPhiMinimizedAgentTelemetry(): void
    {
        $entry = new RequestLog(
            requestId: '7aa9ef18-3227-4c43-9e5e-b8ae3fb8bbcc',
            userId: 7,
            patientId: 900001,
            decision: 'allowed',
            latencyMs: 42,
            timestamp: new DateTimeImmutable('2026-04-30T12:00:00+00:00'),
            telemetry: new AgentTelemetry(
                questionType: 'lab',
                toolsCalled: ['Recent labs'],
                skippedChartSections: ['Active medications', 'Recent notes and last plan'],
                sourceIds: ['lab:procedure_result/agentforge-a1c-2026-04@2026-04-10'],
                model: 'fixture-draft-provider',
                inputTokens: 0,
                outputTokens: 0,
                estimatedCost: null,
                failureReason: null,
                verifierResult: 'passed',
                stageTimingsMs: ['evidence:Recent labs' => 12, 'draft' => 80, 'verify' => 3],
            ),
        );

        $context = $entry->toContext();

        $this->assertSame('lab', $context['question_type']);
        $this->assertSame(['Recent labs'], $context['tools_called']);
        $this->assertSame(['Active medications', 'Recent notes and last plan'], $context['skipped_chart_sections']);
        $this->assertSame(['lab:procedure_result/agentforge-a1c-2026-04@2026-04-10'], $context['source_ids']);
        $this->assertSame('fixture-draft-provider', $context['model']);
        $this->assertSame(0, $context['input_tokens']);
        $this->assertSame(0, $context['output_tokens']);
        $this->assertNull($context['estimated_cost']);
        $this->assertNull($context['failure_reason']);
        $this->assertSame('passed', $context['verifier_result']);
        $this->assertSame(
            ['evidence:Recent labs' => 12, 'draft' => 80, 'verify' => 3],
            $context['stage_timings_ms'],
        );
        $this->assertArrayNotHasKey('question', $context);
        $this->assertArrayNotHasKey('answer', $context);
        $this->assertArrayNotHasKey('full_prompt', $context);
    }

    public function testPsrRequestLoggerWritesSingleDefaultVisibleEvent(): void
    {
        $logger = new RecordingLogger();
        $entry = new RequestLog(
            requestId: '7aa9ef18-3227-4c43-9e5e-b8ae3fb8bbcc',
            userId: null,
            patientId: null,
            decision: 'refused_bad_csrf',
            latencyMs: 0,
            timestamp: new DateTimeImmutable('2026-04-30T12:00:00+00:00'),
        );

        (new PsrRequestLogger($logger))->record($entry);

        $this->assertCount(1, $logger->records);
        $this->assertSame('warning', $logger->records[0]['level']);
        $this->assertSame('agent_forge_request', $logger->records[0]['message']);
        $this->assertSame('refused_bad_csrf', $logger->records[0]['context']['decision']);
        $this->assertArrayNotHasKey('question', $logger->records[0]['context']);
    }

    public function testPsrRequestLoggerDoesNotEmitNestedForbiddenTelemetryKeys(): void
    {
        $logger = new RecordingLogger();
        $entry = new RequestLog(
            requestId: '7aa9ef18-3227-4c43-9e5e-b8ae3fb8bbcc',
            userId: 7,
            patientId: 900001,
            decision: 'allowed',
            latencyMs: 42,
            timestamp: new DateTimeImmutable('2026-04-30T12:00:00+00:00'),
            telemetry: $this->malformedTelemetryForSanitizationProof(),
        );

        (new PsrRequestLogger($logger))->record($entry);

        $context = $logger->records[0]['context'];
        $this->assertFalse(SensitiveLogPolicy::containsForbiddenKey($context));
        $encoded = json_encode($context, JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString('Alice Chen', $encoded);
        $this->assertStringNotContainsString('raw document', $encoded);
        $this->assertStringNotContainsString('LDL 158', $encoded);
    }

    private function malformedTelemetryForSanitizationProof(): AgentTelemetry
    {
        return new AgentTelemetry(
            questionType: 'lab',
            toolsCalled: [['name' => 'Recent labs', 'document_text' => 'raw document']], // @phpstan-ignore argument.type
            skippedChartSections: [],
            sourceIds: [['patient_name' => 'Alice Chen', 'source_id' => 'doc:1']], // @phpstan-ignore argument.type
            model: 'fixture-draft-provider',
            inputTokens: 0,
            outputTokens: 0,
            estimatedCost: null,
            failureReason: null,
            verifierResult: 'passed',
            stageTimingsMs: ['draft' => 20, 'raw_value' => 'LDL 158'], // @phpstan-ignore argument.type
        );
    }
}

final class RecordingLogger extends AbstractLogger
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
