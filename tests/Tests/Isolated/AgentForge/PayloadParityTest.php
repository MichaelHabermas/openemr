<?php

/**
 * Locks the AgentForge LLM provider wire format against accidental drift.
 *
 * Each provider builds its outbound JSON payload from a deterministic input
 * fixture; the captured payload is asserted against a JSON snapshot. After
 * the P3 LLM provider lifecycle refactor (AbstractLlmProvider, HttpClientBuilder,
 * LlmCredentialGuard), the snapshots here keep future refactors honest:
 * any unintended payload change fails this test with a precise diff.
 *
 * Set the environment variable AGENTFORGE_PAYLOAD_PARITY_UPDATE=1 to regenerate
 * the snapshot files after an intentional payload change.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Tests\Isolated\AgentForge;

use OpenEMR\AgentForge\Auth\PatientId;
use OpenEMR\AgentForge\Deadline;
use OpenEMR\AgentForge\Evidence\EvidenceBundle;
use OpenEMR\AgentForge\Evidence\EvidenceBundleItem;
use OpenEMR\AgentForge\Handlers\AgentQuestion;
use OpenEMR\AgentForge\Handlers\AgentRequest;
use OpenEMR\AgentForge\ResponseGeneration\AnthropicDraftProvider;
use OpenEMR\AgentForge\ResponseGeneration\DraftClaim;
use OpenEMR\AgentForge\ResponseGeneration\OpenAiDraftProvider;
use OpenEMR\AgentForge\ResponseGeneration\PromptComposer;
use OpenEMR\AgentForge\Time\SystemMonotonicClock;
use OpenEMR\Tests\Isolated\AgentForge\Support\RecordingHttpClient;
use PHPUnit\Framework\TestCase;

final class PayloadParityTest extends TestCase
{
    private const FIXTURE_DIR = __DIR__ . '/fixtures/payload-parity';
    private const UPDATE_ENV = 'AGENTFORGE_PAYLOAD_PARITY_UPDATE';

    public function testOpenAiDraftPayloadMatchesSnapshot(): void
    {
        $client = new RecordingHttpClient($this->openAiResponseStub());
        $provider = new OpenAiDraftProvider($client, 'test-key', 'gpt-4o-mini', 0.15, 0.60);

        $provider->draft($this->request(), $this->bundle(), $this->deadline());

        $this->assertPayloadMatchesSnapshot('openai-draft.json', $client->lastPayload());
    }

    public function testAnthropicDraftPayloadMatchesSnapshot(): void
    {
        $client = new RecordingHttpClient($this->anthropicResponseStub());
        $provider = new AnthropicDraftProvider(
            $client,
            'test-anthropic-key',
            'claude-haiku-4-5-20251001',
            1.00,
            5.00,
        );

        $provider->draft($this->request(), $this->bundle(), $this->deadline());

        $this->assertPayloadMatchesSnapshot('anthropic-draft.json', $client->lastPayload());
    }

    /** @param array<string, mixed> $payload */
    private function assertPayloadMatchesSnapshot(string $fixtureName, array $payload): void
    {
        $path = self::FIXTURE_DIR . '/' . $fixtureName;
        $encoded = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

        if (getenv(self::UPDATE_ENV) === '1') {
            file_put_contents($path, $encoded . "\n");
            $this->markTestSkipped(sprintf('Updated snapshot %s.', $fixtureName));
        }

        $this->assertFileExists(
            $path,
            sprintf(
                'Snapshot %s does not exist. Run with %s=1 to generate it.',
                $fixtureName,
                self::UPDATE_ENV,
            ),
        );

        $expected = file_get_contents($path);
        $this->assertNotFalse($expected, sprintf('Failed to read snapshot %s.', $fixtureName));
        $this->assertSame(trim((string) $expected), trim($encoded), sprintf(
            'Payload diverged from snapshot %s. If this change is intentional, regenerate with %s=1.',
            $fixtureName,
            self::UPDATE_ENV,
        ));
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
        return new Deadline(new SystemMonotonicClock(), 8000);
    }

    /** @return array<string, mixed> */
    private function openAiResponseStub(): array
    {
        return [
            'choices' => [
                [
                    'message' => [
                        'content' => json_encode([
                            'sentences' => [
                                ['id' => 's1', 'text' => 'Hemoglobin A1c: 7.4 % [lab:procedure_result/agentforge-a1c-2026-04@2026-04-10]'],
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

    /** @return array<string, mixed> */
    private function anthropicResponseStub(): array
    {
        return [
            'stop_reason' => 'tool_use',
            'content' => [
                [
                    'type' => 'tool_use',
                    'name' => PromptComposer::SCHEMA_NAME,
                    'input' => [
                        'sentences' => [
                            ['id' => 's1', 'text' => 'Hemoglobin A1c: 7.4 % [lab:procedure_result/agentforge-a1c-2026-04@2026-04-10]'],
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
                ],
            ],
            'usage' => [
                'input_tokens' => 50,
                'cache_creation_input_tokens' => 150,
                'cache_read_input_tokens' => 20,
                'output_tokens' => 40,
            ],
        ];
    }
}
