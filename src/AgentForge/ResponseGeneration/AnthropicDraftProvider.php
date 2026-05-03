<?php

/**
 * Anthropic-backed draft provider with prompt caching for AgentForge.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\AgentForge\ResponseGeneration;

use DomainException;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;
use OpenEMR\AgentForge\Deadline;
use OpenEMR\AgentForge\Evidence\EvidenceBundle;
use OpenEMR\AgentForge\Handlers\AgentRequest;
use Psr\Http\Message\ResponseInterface;
use SensitiveParameter;

final readonly class AnthropicDraftProvider implements DraftProvider
{
    private const ANTHROPIC_VERSION = '2023-06-01';
    private const TOOL_NAME = PromptComposer::SCHEMA_NAME;
    private const DEFAULT_MAX_TOKENS = 2048;
    private const ANTHROPIC_CACHE_WRITE_MULTIPLIER = 1.25;
    private const ANTHROPIC_CACHE_READ_MULTIPLIER = 0.10;

    public function __construct(
        private ClientInterface $client,
        #[SensitiveParameter] private string $apiKey,
        private string $model,
        private ?float $inputCostPerMillionTokens = null,
        private ?float $outputCostPerMillionTokens = null,
        private ?float $cacheWriteCostPerMillionTokens = null,
        private ?float $cacheReadCostPerMillionTokens = null,
        private float $configuredTimeoutSeconds = 15.0,
        private int $maxOutputTokens = self::DEFAULT_MAX_TOKENS,
        private PromptComposer $promptComposer = new PromptComposer(),
    ) {
        if (trim($apiKey) === '') {
            throw new DomainException('Anthropic draft provider requires an API key.');
        }
        if (trim($model) === '') {
            throw new DomainException('Anthropic draft provider requires a model.');
        }
        if ($maxOutputTokens <= 0) {
            throw new DomainException('Anthropic draft provider max output tokens must be positive.');
        }
    }

    public function draft(AgentRequest $request, EvidenceBundle $bundle, Deadline $deadline): DraftResponse
    {
        if ($deadline->exceeded()) {
            throw new DraftProviderException('Deadline exceeded before Anthropic draft request.');
        }

        $timeoutSeconds = min($this->configuredTimeoutSeconds, $deadline->remainingSeconds());

        try {
            $response = $this->client->request('POST', '/v1/messages', [
                'headers' => [
                    'x-api-key' => $this->apiKey,
                    'anthropic-version' => self::ANTHROPIC_VERSION,
                    'content-type' => 'application/json',
                ],
                'json' => $this->payload($request, $bundle),
                'timeout' => $timeoutSeconds,
                DraftProviderRetryMiddleware::DEADLINE_OPTION => $deadline,
            ]);
        } catch (GuzzleException $exception) {
            throw new DraftProviderException('Anthropic draft request failed.', previous: $exception);
        }

        return $this->parseResponse($response);
    }

    /** @return array<string, mixed> */
    private function payload(AgentRequest $request, EvidenceBundle $bundle): array
    {
        return [
            'model' => $this->model,
            'max_tokens' => $this->maxOutputTokens,
            'temperature' => 0,
            'system' => [
                [
                    'type' => 'text',
                    'text' => $this->promptComposer->systemPrompt(),
                    'cache_control' => ['type' => 'ephemeral'],
                ],
            ],
            'tools' => [
                [
                    'name' => self::TOOL_NAME,
                    'description' => 'Return the structured AgentForge draft response. Use only the supplied bounded evidence.',
                    'input_schema' => $this->promptComposer->schema(),
                    'cache_control' => ['type' => 'ephemeral'],
                ],
            ],
            'tool_choice' => [
                'type' => 'tool',
                'name' => self::TOOL_NAME,
            ],
            'messages' => [
                [
                    'role' => 'user',
                    'content' => $this->userMessageContent($request, $bundle),
                ],
            ],
        ];
    }

    /**
     * Emits the user message as two text blocks. The stable evidence prefix carries an
     * ephemeral cache_control breakpoint so the next turn within the cache window only pays
     * for the delta question's tokens.
     *
     * @return list<array<string, mixed>>
     */
    private function userMessageContent(AgentRequest $request, EvidenceBundle $bundle): array
    {
        $parts = $this->promptComposer->userMessageParts($request, $bundle);

        return [
            [
                'type' => 'text',
                'text' => $parts->stableEvidence,
                'cache_control' => ['type' => 'ephemeral'],
            ],
            [
                'type' => 'text',
                'text' => $parts->deltaQuestion,
            ],
        ];
    }

    private function parseResponse(ResponseInterface $response): DraftResponse
    {
        $body = $this->jsonObject((string) $response->getBody(), 'Anthropic draft response');
        $contentList = $this->arrayFromMixed($body['content'] ?? null, 'Anthropic content');
        $stopReason = $body['stop_reason'] ?? null;

        if ($stopReason === 'refusal') {
            $refusalText = $this->extractTextContent($contentList);
            return DraftResponse::singleRefusal(
                $refusalText !== '' ? $refusalText : 'I cannot assist with that request.',
                $this->usageFromResponse($body),
                [],
            );
        }

        $toolUse = $this->findToolUseBlock($contentList);
        if ($toolUse === null) {
            $textContent = $this->extractTextContent($contentList);
            if ($textContent !== '') {
                return DraftResponse::singleRefusal($textContent, $this->usageFromResponse($body), []);
            }
            throw new DomainException('Anthropic draft response did not include tool use output.');
        }

        $input = $this->objectFromMixed($toolUse['input'] ?? null, 'Anthropic tool input');

        return new DraftResponse(
            $this->sentencesFromDraft($input),
            $this->claimsFromDraft($input),
            $this->stringsFromDraft($input, 'missing_sections'),
            $this->stringsFromDraft($input, 'refusals_or_warnings'),
            $this->usageFromResponse($body),
        );
    }

    /**
     * @param list<mixed> $contentList
     * @return array<string, mixed>|null
     */
    private function findToolUseBlock(array $contentList): ?array
    {
        foreach ($contentList as $block) {
            if (!is_array($block)) {
                continue;
            }
            if (($block['type'] ?? null) !== 'tool_use' || ($block['name'] ?? null) !== self::TOOL_NAME) {
                continue;
            }

            return $this->objectFromMixed($block, 'Anthropic tool use block');
        }

        return null;
    }

    /** @param list<mixed> $contentList */
    private function extractTextContent(array $contentList): string
    {
        $segments = [];
        foreach ($contentList as $block) {
            if (
                is_array($block)
                && ($block['type'] ?? null) === 'text'
                && isset($block['text'])
                && is_string($block['text'])
            ) {
                $segments[] = $block['text'];
            }
        }

        return trim(implode(' ', $segments));
    }

    /**
     * @param array<string, mixed> $draft
     * @return list<DraftSentence>
     */
    private function sentencesFromDraft(array $draft): array
    {
        $sentences = [];
        foreach ($this->arrayFromMixed($draft['sentences'] ?? null, 'Anthropic sentences') as $sentence) {
            $sentenceObject = $this->objectFromMixed($sentence, 'Anthropic sentence');
            $sentences[] = new DraftSentence(
                $this->stringField($sentenceObject, 'id'),
                $this->stringField($sentenceObject, 'text'),
            );
        }

        return $sentences;
    }

    /**
     * @param array<string, mixed> $draft
     * @return list<DraftClaim>
     */
    private function claimsFromDraft(array $draft): array
    {
        $claims = [];
        foreach ($this->arrayFromMixed($draft['claims'] ?? null, 'Anthropic claims') as $claim) {
            $claimObject = $this->objectFromMixed($claim, 'Anthropic claim');
            $claims[] = new DraftClaim(
                $this->stringField($claimObject, 'text'),
                $this->stringField($claimObject, 'type'),
                $this->stringList($claimObject['cited_source_ids'] ?? null, 'Anthropic cited source IDs'),
                $this->stringField($claimObject, 'sentence_id'),
            );
        }

        return $claims;
    }

    /**
     * @param array<string, mixed> $draft
     * @return list<string>
     */
    private function stringsFromDraft(array $draft, string $key): array
    {
        return $this->stringList($draft[$key] ?? null, sprintf('Anthropic %s', $key));
    }

    /** @return list<string> */
    private function stringList(mixed $value, string $label): array
    {
        if (!is_array($value)) {
            throw new DomainException(sprintf('%s must be an array.', $label));
        }

        $values = [];
        foreach ($value as $item) {
            if (!is_string($item)) {
                throw new DomainException(sprintf('%s contained a non-string.', $label));
            }
            $values[] = $item;
        }

        return $values;
    }

    /** @return list<mixed> */
    private function arrayFromMixed(mixed $value, string $label): array
    {
        if (!is_array($value)) {
            throw new DomainException(sprintf('%s must be an array.', $label));
        }

        return array_values($value);
    }

    /** @return array<string, mixed> */
    private function objectFromMixed(mixed $value, string $label): array
    {
        if (!is_array($value)) {
            throw new DomainException(sprintf('%s was not an object.', $label));
        }

        $object = [];
        foreach ($value as $key => $field) {
            if (!is_string($key)) {
                throw new DomainException(sprintf('%s was not an object.', $label));
            }
            $object[$key] = $field;
        }

        return $object;
    }

    /** @param array<mixed> $source */
    private function stringField(array $source, string $key): string
    {
        $value = $source[$key] ?? null;
        if (!is_string($value) || trim($value) === '') {
            throw new DomainException(sprintf('Anthropic draft field %s must be a non-empty string.', $key));
        }

        return $value;
    }

    /** @return array<string, mixed> */
    private function jsonObject(string $json, string $label): array
    {
        $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);

        return $this->objectFromMixed($decoded, $label);
    }

    /** @param array<string, mixed> $body */
    private function usageFromResponse(array $body): DraftUsage
    {
        $usage = $body['usage'] ?? [];
        $inputTokens = $this->intFromUsage($usage, 'input_tokens');
        $cacheCreationTokens = $this->intFromUsage($usage, 'cache_creation_input_tokens');
        $cacheReadTokens = $this->intFromUsage($usage, 'cache_read_input_tokens');
        $outputTokens = $this->intFromUsage($usage, 'output_tokens');

        return new DraftUsage(
            $this->model,
            $inputTokens + $cacheCreationTokens + $cacheReadTokens,
            $outputTokens,
            $this->estimatedCost($inputTokens, $outputTokens, $cacheCreationTokens, $cacheReadTokens),
        );
    }

    private function intFromUsage(mixed $usage, string $key): int
    {
        if (!is_array($usage) || !isset($usage[$key]) || !is_int($usage[$key])) {
            return 0;
        }

        return $usage[$key];
    }

    private function estimatedCost(
        int $inputTokens,
        int $outputTokens,
        int $cacheCreationTokens,
        int $cacheReadTokens,
    ): ?float {
        if ($this->inputCostPerMillionTokens === null || $this->outputCostPerMillionTokens === null) {
            return null;
        }

        $inputRate = $this->inputCostPerMillionTokens;
        $cacheWriteRate = $this->cacheWriteCostPerMillionTokens
            ?? ($inputRate * self::ANTHROPIC_CACHE_WRITE_MULTIPLIER);
        $cacheReadRate = $this->cacheReadCostPerMillionTokens
            ?? ($inputRate * self::ANTHROPIC_CACHE_READ_MULTIPLIER);

        return (($inputTokens / 1_000_000) * $inputRate)
            + (($cacheCreationTokens / 1_000_000) * $cacheWriteRate)
            + (($cacheReadTokens / 1_000_000) * $cacheReadRate)
            + (($outputTokens / 1_000_000) * $this->outputCostPerMillionTokens);
    }
}
