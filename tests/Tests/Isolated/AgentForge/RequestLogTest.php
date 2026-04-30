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
use OpenEMR\AgentForge\AgentTelemetry;
use OpenEMR\AgentForge\PsrRequestLogger;
use OpenEMR\AgentForge\RequestLog;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;

final class RequestLogTest extends TestCase
{
    public function testRequestLogContextIsPhiFree(): void
    {
        $entry = new RequestLog(
            requestId: '7aa9ef18-3227-4c43-9e5e-b8ae3fb8bbcc',
            userId: 7,
            patientId: 900001,
            decision: 'refused_patient_mismatch',
            latencyMs: 4,
            timestamp: new DateTimeImmutable('2026-04-30T12:00:00+00:00'),
        );

        $context = $entry->toContext();

        $this->assertSame('7aa9ef18-3227-4c43-9e5e-b8ae3fb8bbcc', $context['request_id']);
        $this->assertSame(7, $context['user_id']);
        $this->assertSame(900001, $context['patient_id']);
        $this->assertSame('refused_patient_mismatch', $context['decision']);
        $this->assertGreaterThanOrEqual(0, $context['latency_ms']);
        $this->assertSame('2026-04-30T12:00:00+00:00', $context['timestamp']);
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

    public function testRequestLogContextIncludesPhiFreeAgentTelemetry(): void
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
                sourceIds: ['lab:procedure_result/agentforge-a1c-2026-04@2026-04-10'],
                model: 'fixture-draft-provider',
                inputTokens: 0,
                outputTokens: 0,
                estimatedCost: null,
                failureReason: null,
                verifierResult: 'passed',
            ),
        );

        $context = $entry->toContext();

        $this->assertSame('lab', $context['question_type']);
        $this->assertSame(['Recent labs'], $context['tools_called']);
        $this->assertSame(['lab:procedure_result/agentforge-a1c-2026-04@2026-04-10'], $context['source_ids']);
        $this->assertSame('fixture-draft-provider', $context['model']);
        $this->assertSame(0, $context['input_tokens']);
        $this->assertSame(0, $context['output_tokens']);
        $this->assertNull($context['estimated_cost']);
        $this->assertNull($context['failure_reason']);
        $this->assertSame('passed', $context['verifier_result']);
        $this->assertArrayNotHasKey('question', $context);
        $this->assertArrayNotHasKey('answer', $context);
        $this->assertArrayNotHasKey('full_prompt', $context);
    }

    public function testPsrRequestLoggerWritesSingleInfoEvent(): void
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
        $this->assertSame('info', $logger->records[0]['level']);
        $this->assertSame('agent_forge_request', $logger->records[0]['message']);
        $this->assertSame('refused_bad_csrf', $logger->records[0]['context']['decision']);
        $this->assertArrayNotHasKey('question', $logger->records[0]['context']);
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
