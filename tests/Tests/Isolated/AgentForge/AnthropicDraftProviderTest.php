<?php

/**
 * Isolated tests for AgentForge Anthropic structured draft provider.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Tests\Isolated\AgentForge;

use BadMethodCallException;
use DomainException;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Psr7\Response;
use OpenEMR\AgentForge\Auth\PatientId;
use OpenEMR\AgentForge\Deadline;
use OpenEMR\AgentForge\Evidence\EvidenceBundle;
use OpenEMR\AgentForge\Evidence\EvidenceBundleItem;
use OpenEMR\AgentForge\Handlers\AgentQuestion;
use OpenEMR\AgentForge\Handlers\AgentRequest;
use OpenEMR\AgentForge\ResponseGeneration\AnthropicDraftProvider;
use OpenEMR\AgentForge\ResponseGeneration\DraftClaim;
use OpenEMR\AgentForge\ResponseGeneration\PromptComposer;
use OpenEMR\AgentForge\SystemAgentForgeClock;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

final class AnthropicDraftProviderTest extends TestCase
{
    public function testDraftSendsCacheControlAndParsesToolUseResponse(): void
    {
        $client = new RecordingAnthropicClient($this->toolUseResponse());
        $provider = new AnthropicDraftProvider(
            $client,
            'test-anthropic-key',
            'claude-haiku-4-5-20251001',
            1.00,
            5.00,
        );

        $draft = $provider->draft($this->request(), $this->bundle(), $this->deadline());

        $this->assertSame(
            'Hemoglobin A1c: 7.4 % [lab:procedure_result/agentforge-a1c-2026-04@2026-04-10]',
            $draft->sentences[0]->text,
        );
        $this->assertSame(DraftClaim::TYPE_PATIENT_FACT, $draft->claims[0]->type);
        $this->assertSame(
            ['lab:procedure_result/agentforge-a1c-2026-04@2026-04-10'],
            $draft->claims[0]->citedSourceIds,
        );
        $this->assertSame('claude-haiku-4-5-20251001', $draft->usage->model);
        $this->assertSame(220, $draft->usage->inputTokens);
        $this->assertSame(40, $draft->usage->outputTokens);
        $this->assertEqualsWithDelta(
            (50.0 * 1.00 + 150.0 * 1.25 + 20.0 * 0.10 + 40.0 * 5.00) / 1_000_000,
            (float) $draft->usage->estimatedCost,
            1e-9,
        );

        $payload = $client->lastPayload();
        $this->assertSame('claude-haiku-4-5-20251001', $payload['model']);
        $this->assertSame(0, $payload['temperature']);
        $this->assertSame(2048, $payload['max_tokens']);

        $this->assertSame('text', $this->stringPath($payload, ['system', 0, 'type']));
        $this->assertSame(['type' => 'ephemeral'], $this->arrayPath($payload, ['system', 0, 'cache_control']));
        $systemText = $this->stringPath($payload, ['system', 0, 'text']);
        $this->assertStringContainsString('Use only the supplied bounded evidence JSON.', $systemText);
        $this->assertStringContainsString('Answer only the clinician question', $systemText);
        $this->assertStringContainsString('copy the cited evidence display_label and value exactly', $systemText);

        $this->assertSame(PromptComposer::SCHEMA_NAME, $this->stringPath($payload, ['tools', 0, 'name']));
        $this->assertSame(['type' => 'ephemeral'], $this->arrayPath($payload, ['tools', 0, 'cache_control']));
        $this->assertNotEmpty($this->arrayPath($payload, ['tools', 0, 'input_schema']));

        $this->assertSame(
            ['type' => 'tool', 'name' => PromptComposer::SCHEMA_NAME],
            $this->arrayPath($payload, ['tool_choice']),
        );

        $this->assertSame('user', $this->stringPath($payload, ['messages', 0, 'role']));
        $this->assertStringContainsString(
            'Hemoglobin A1c',
            $this->stringPath($payload, ['messages', 0, 'content']),
        );

        $headers = $client->lastHeaders();
        $this->assertSame('test-anthropic-key', $headers['x-api-key']);
        $this->assertSame('2023-06-01', $headers['anthropic-version']);
        $this->assertSame('application/json', $headers['content-type']);
    }

    /**
     * @param array<string, mixed> $source
     * @param list<int|string> $path
     */
    private function stringPath(array $source, array $path): string
    {
        $value = $this->traversePath($source, $path);
        if (!is_string($value)) {
            $this->fail('Expected payload path to contain a string.');
        }

        return $value;
    }

    /**
     * @param array<string, mixed> $source
     * @param list<int|string> $path
     * @return array<int|string, mixed>
     */
    private function arrayPath(array $source, array $path): array
    {
        $value = $this->traversePath($source, $path);
        if (!is_array($value)) {
            $this->fail('Expected payload path to contain an array.');
        }

        return $value;
    }

    /**
     * @param array<string, mixed> $source
     * @param list<int|string> $path
     */
    private function traversePath(array $source, array $path): mixed
    {
        $value = $source;
        foreach ($path as $segment) {
            if (!is_array($value) || !array_key_exists($segment, $value)) {
                $this->fail('Expected payload path was missing.');
            }
            $value = $value[$segment];
        }

        return $value;
    }

    public function testStopReasonRefusalProducesRefusalDraft(): void
    {
        $client = new RecordingAnthropicClient([
            'stop_reason' => 'refusal',
            'content' => [
                ['type' => 'text', 'text' => 'I cannot assist with that request.'],
            ],
            'usage' => [
                'input_tokens' => 11,
                'cache_creation_input_tokens' => 0,
                'cache_read_input_tokens' => 0,
                'output_tokens' => 7,
            ],
        ]);
        $provider = new AnthropicDraftProvider(
            $client,
            'test-key',
            'claude-haiku-4-5-20251001',
        );

        $draft = $provider->draft($this->request(), $this->bundle(), $this->deadline());

        $this->assertSame(DraftClaim::TYPE_REFUSAL, $draft->claims[0]->type);
        $this->assertSame('I cannot assist with that request.', $draft->sentences[0]->text);
        $this->assertNull($draft->usage->estimatedCost);
    }

    public function testTextOnlyContentBecomesRefusal(): void
    {
        $client = new RecordingAnthropicClient([
            'stop_reason' => 'end_turn',
            'content' => [
                ['type' => 'text', 'text' => 'I am unable to provide that information.'],
            ],
            'usage' => [
                'input_tokens' => 10,
                'cache_creation_input_tokens' => 0,
                'cache_read_input_tokens' => 0,
                'output_tokens' => 8,
            ],
        ]);
        $provider = new AnthropicDraftProvider(
            $client,
            'test-key',
            'claude-haiku-4-5-20251001',
        );

        $draft = $provider->draft($this->request(), $this->bundle(), $this->deadline());

        $this->assertSame(DraftClaim::TYPE_REFUSAL, $draft->claims[0]->type);
        $this->assertSame('I am unable to provide that information.', $draft->sentences[0]->text);
    }

    public function testMissingToolUseAndTextRaises(): void
    {
        $client = new RecordingAnthropicClient([
            'stop_reason' => 'end_turn',
            'content' => [],
            'usage' => [
                'input_tokens' => 5,
                'cache_creation_input_tokens' => 0,
                'cache_read_input_tokens' => 0,
                'output_tokens' => 0,
            ],
        ]);
        $provider = new AnthropicDraftProvider(
            $client,
            'test-key',
            'claude-haiku-4-5-20251001',
        );

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('did not include tool use output');

        $provider->draft($this->request(), $this->bundle(), $this->deadline());
    }

    public function testCacheReadHeavyUsageReducesEstimatedCost(): void
    {
        $client = new RecordingAnthropicClient([
            'stop_reason' => 'tool_use',
            'content' => [$this->successfulToolUseBlock()],
            'usage' => [
                'input_tokens' => 10,
                'cache_creation_input_tokens' => 0,
                'cache_read_input_tokens' => 1000,
                'output_tokens' => 30,
            ],
        ]);
        $provider = new AnthropicDraftProvider(
            $client,
            'test-key',
            'claude-haiku-4-5-20251001',
            1.00,
            5.00,
        );

        $draft = $provider->draft($this->request(), $this->bundle(), $this->deadline());

        // 10 * 1.00 + 0 * 1.25 + 1000 * 0.10 + 30 * 5.00 = 10 + 0 + 100 + 150 = 260; / 1M = 0.00026
        $this->assertEqualsWithDelta(0.00026, (float) $draft->usage->estimatedCost, 1e-9);
        $this->assertSame(1010, $draft->usage->inputTokens);
    }

    /** @return array<string, mixed> */
    private function toolUseResponse(): array
    {
        return [
            'stop_reason' => 'tool_use',
            'content' => [$this->successfulToolUseBlock()],
            'usage' => [
                'input_tokens' => 50,
                'cache_creation_input_tokens' => 150,
                'cache_read_input_tokens' => 20,
                'output_tokens' => 40,
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function successfulToolUseBlock(): array
    {
        return [
            'type' => 'tool_use',
            'name' => PromptComposer::SCHEMA_NAME,
            'input' => [
                'sentences' => [
                    [
                        'id' => 's1',
                        'text' => 'Hemoglobin A1c: 7.4 % [lab:procedure_result/agentforge-a1c-2026-04@2026-04-10]',
                    ],
                ],
                'claims' => [
                    [
                        'text' => 'Hemoglobin A1c: 7.4 %',
                        'type' => DraftClaim::TYPE_PATIENT_FACT,
                        'cited_source_ids' => ['lab:procedure_result/agentforge-a1c-2026-04@2026-04-10'],
                        'sentence_id' => 's1',
                    ],
                ],
                'missing_sections' => [],
                'refusals_or_warnings' => [],
            ],
        ];
    }

    private function request(): AgentRequest
    {
        return new AgentRequest(new PatientId(900001), new AgentQuestion('Show me recent A1c.'));
    }

    private function bundle(): EvidenceBundle
    {
        return new EvidenceBundle([
            new EvidenceBundleItem(
                'lab',
                'lab:procedure_result/agentforge-a1c-2026-04@2026-04-10',
                '2026-04-10',
                'Hemoglobin A1c',
                '7.4 %',
            ),
        ]);
    }

    private function deadline(): Deadline
    {
        return new Deadline(new SystemAgentForgeClock(), 8000);
    }
}

final class RecordingAnthropicClient implements ClientInterface
{
    /** @var array<string, mixed>|null */
    private ?array $payload = null;

    /** @var array<string, string> */
    private array $headers = [];

    /** @param array<string, mixed> $responseBody */
    public function __construct(private readonly array $responseBody)
    {
    }

    /** @param array<mixed> $options */
    public function send(RequestInterface $request, array $options = []): ResponseInterface
    {
        throw new BadMethodCallException('send is not used by this test client.');
    }

    /** @param array<mixed> $options */
    public function sendAsync(RequestInterface $request, array $options = []): PromiseInterface
    {
        throw new BadMethodCallException('sendAsync is not used by this test client.');
    }

    /** @param array<mixed> $options */
    public function request(string $method, $uri, array $options = []): ResponseInterface
    {
        $json = $options['json'] ?? null;
        if (!is_array($json)) {
            throw new BadMethodCallException('Expected JSON request payload.');
        }
        $this->payload = $this->stringKeyedArray($json);

        $headers = $options['headers'] ?? [];
        if (is_array($headers)) {
            $stringHeaders = [];
            foreach ($headers as $name => $value) {
                if (!is_string($name) || !is_string($value)) {
                    throw new BadMethodCallException('Expected string-keyed header values.');
                }
                $stringHeaders[$name] = $value;
            }
            $this->headers = $stringHeaders;
        }

        return new Response(200, [], json_encode($this->responseBody, JSON_THROW_ON_ERROR));
    }

    /** @param array<mixed> $options */
    public function requestAsync(string $method, $uri, array $options = []): PromiseInterface
    {
        throw new BadMethodCallException('requestAsync is not used by this test client.');
    }

    public function getConfig(?string $option = null): mixed
    {
        return null;
    }

    /** @return array<string, mixed> */
    public function lastPayload(): array
    {
        if ($this->payload === null) {
            throw new BadMethodCallException('No request payload was recorded.');
        }

        return $this->payload;
    }

    /** @return array<string, string> */
    public function lastHeaders(): array
    {
        return $this->headers;
    }

    /**
     * @param array<mixed> $source
     * @return array<string, mixed>
     */
    private function stringKeyedArray(array $source): array
    {
        $result = [];
        foreach ($source as $key => $value) {
            if (!is_string($key)) {
                throw new BadMethodCallException('Expected string-keyed JSON payload.');
            }
            $result[$key] = $value;
        }

        return $result;
    }
}
