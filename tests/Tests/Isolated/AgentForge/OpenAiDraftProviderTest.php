<?php

/**
 * Isolated tests for AgentForge OpenAI structured draft provider.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Tests\Isolated\AgentForge;

use BadMethodCallException;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Psr7\Response;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\UriInterface;
use OpenEMR\AgentForge\AgentQuestion;
use OpenEMR\AgentForge\AgentRequest;
use OpenEMR\AgentForge\DraftClaim;
use OpenEMR\AgentForge\EvidenceBundle;
use OpenEMR\AgentForge\EvidenceBundleItem;
use OpenEMR\AgentForge\OpenAiDraftProvider;
use OpenEMR\AgentForge\PatientId;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;

final class OpenAiDraftProviderTest extends TestCase
{
    public function testDraftSendsBoundedEvidenceAndParsesStructuredResponse(): void
    {
        $client = new RecordingOpenAiClient($this->openAiResponse());
        $provider = new OpenAiDraftProvider($client, 'test-key', 'gpt-4o-mini', 0.15, 0.60);

        $draft = $provider->draft($this->request(), $this->bundle());

        $this->assertSame('Hemoglobin A1c: 7.4 % [lab:procedure_result/agentforge-a1c-2026-04@2026-04-10]', $draft->sentences[0]->text);
        $this->assertSame(DraftClaim::TYPE_PATIENT_FACT, $draft->claims[0]->type);
        $this->assertSame(['lab:procedure_result/agentforge-a1c-2026-04@2026-04-10'], $draft->claims[0]->citedSourceIds);
        $this->assertSame('gpt-4o-mini', $draft->usage->model);
        $this->assertSame(100, $draft->usage->inputTokens);
        $this->assertSame(40, $draft->usage->outputTokens);
        $this->assertSame(0.000039, $draft->usage->estimatedCost);

        $payload = $client->lastPayload();
        $this->assertSame('gpt-4o-mini', $payload['model']);
        $this->assertSame(0, $payload['temperature']);
        $this->assertSame('json_schema', $this->stringPath($payload, ['response_format', 'type']));
        $this->assertStringContainsString(
            'Use only the supplied bounded evidence JSON.',
            $this->stringPath($payload, ['messages', 0, 'content']),
        );
        $this->assertStringContainsString(
            'copy the cited evidence display_label and value exactly',
            $this->stringPath($payload, ['messages', 0, 'content']),
        );
        $this->assertStringContainsString(
            'Answer only the clinician question',
            $this->stringPath($payload, ['messages', 0, 'content']),
        );
        $this->assertStringNotContainsString(
            'full chart',
            strtolower($this->stringPath($payload, ['messages', 1, 'content'])),
        );
        $this->assertStringContainsString(
            'Hemoglobin A1c',
            $this->stringPath($payload, ['messages', 1, 'content']),
        );
    }

    public function testRefusalMessageBecomesRefusalDraft(): void
    {
        $client = new RecordingOpenAiClient([
            'choices' => [
                ['message' => ['refusal' => 'I cannot assist with that request.']],
            ],
            'usage' => ['prompt_tokens' => 11, 'completion_tokens' => 7],
        ]);
        $provider = new OpenAiDraftProvider($client, 'test-key', 'gpt-4o-mini');

        $draft = $provider->draft($this->request(), $this->bundle());

        $this->assertSame(DraftClaim::TYPE_REFUSAL, $draft->claims[0]->type);
        $this->assertSame('I cannot assist with that request.', $draft->sentences[0]->text);
        $this->assertNull($draft->usage->estimatedCost);
    }

    /** @return array<string, mixed> */
    private function openAiResponse(): array
    {
        return [
            'choices' => [
                [
                    'message' => [
                        'content' => json_encode([
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
                        ], JSON_THROW_ON_ERROR),
                    ],
                ],
            ],
            'usage' => ['prompt_tokens' => 100, 'completion_tokens' => 40],
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

    /**
     * @param array<string, mixed> $source
     * @param list<int|string> $path
     */
    private function stringPath(array $source, array $path): string
    {
        $value = $source;
        foreach ($path as $segment) {
            if (!is_array($value) || !array_key_exists($segment, $value)) {
                $this->fail('Expected payload path was missing.');
            }
            $value = $value[$segment];
        }
        if (!is_string($value)) {
            $this->fail('Expected payload path to contain a string.');
        }

        return $value;
    }
}

final class RecordingOpenAiClient implements ClientInterface
{
    /** @var array<string, mixed>|null */
    private ?array $payload = null;

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
