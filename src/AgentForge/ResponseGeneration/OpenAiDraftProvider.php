<?php

/**
 * OpenAI-backed structured draft provider for bounded AgentForge evidence.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\AgentForge\ResponseGeneration;

use DomainException;
use GuzzleHttp\ClientInterface;
use OpenEMR\AgentForge\Deadline;
use OpenEMR\AgentForge\Evidence\EvidenceBundle;
use OpenEMR\AgentForge\Llm\AbstractLlmProvider;
use OpenEMR\AgentForge\Llm\LlmCredentialGuard;
use OpenEMR\AgentForge\Llm\TokenCostEstimator;
use Psr\Http\Message\ResponseInterface;
use SensitiveParameter;

final readonly class OpenAiDraftProvider extends AbstractLlmProvider implements DraftProvider
{
    public function __construct(
        ClientInterface $client,
        #[SensitiveParameter] private string $apiKey,
        private string $model,
        private ?float $inputCostPerMillionTokens = null,
        private ?float $outputCostPerMillionTokens = null,
        private float $configuredTimeoutSeconds = 15.0,
        private PromptComposer $promptComposer = new PromptComposer(),
    ) {
        LlmCredentialGuard::requireApiKey($apiKey, 'OpenAI draft provider');
        LlmCredentialGuard::requireModel($model, 'OpenAI draft provider');

        parent::__construct($client);
    }

    public function draft(DraftRequest $request, EvidenceBundle $bundle, Deadline $deadline): DraftResponse
    {
        $response = $this->dispatch(
            'POST',
            '/v1/chat/completions',
            [
                'headers' => [
                    'Authorization' => sprintf('Bearer %s', $this->apiKey),
                    'Content-Type' => 'application/json',
                ],
                'json' => $this->payload($request, $bundle),
            ],
            $deadline,
            'OpenAI draft',
        );

        return $this->parseResponse($response);
    }

    protected function configuredTimeoutSeconds(): float
    {
        return $this->configuredTimeoutSeconds;
    }

    protected function exceptionClass(): string
    {
        return DraftProviderException::class;
    }

    /** @return array<string, mixed> */
    private function payload(DraftRequest $request, EvidenceBundle $bundle): array
    {
        return [
            'model' => $this->model,
            'messages' => [
                [
                    'role' => 'system',
                    'content' => $this->promptComposer->systemPrompt(),
                ],
                [
                    'role' => 'user',
                    'content' => $this->promptComposer->userMessage($request, $bundle),
                ],
            ],
            'response_format' => [
                'type' => 'json_schema',
                'json_schema' => [
                    'name' => PromptComposer::SCHEMA_NAME,
                    'strict' => true,
                    'schema' => $this->promptComposer->schema(),
                ],
            ],
            'temperature' => 0,
        ];
    }

    private function parseResponse(ResponseInterface $response): DraftResponse
    {
        $body = $this->jsonObject((string) $response->getBody(), 'OpenAI draft response');

        $choices = $this->arrayFromDraft($body, 'choices');
        $firstChoice = $choices[0] ?? null;
        if (!is_array($firstChoice)) {
            throw new DomainException('OpenAI draft response did not include a choice.');
        }
        $message = $this->objectFromMixed($firstChoice['message'] ?? null, 'OpenAI draft response message');
        if ($message === []) {
            throw new DomainException('OpenAI draft response did not include a message.');
        }

        $refusal = $message['refusal'] ?? null;
        if (is_string($refusal) && trim($refusal) !== '') {
            return DraftResponse::singleRefusal($refusal, $this->usageFromResponse($body), []);
        }

        $content = $message['content'] ?? null;
        if (!is_string($content) || trim($content) === '') {
            throw new DomainException('OpenAI draft response content was empty.');
        }

        $draft = $this->jsonObject($content, 'OpenAI draft content');

        return new DraftResponse(
            $this->sentencesFromDraft($draft),
            $this->claimsFromDraft($draft),
            $this->stringsFromDraft($draft, 'missing_sections'),
            $this->stringsFromDraft($draft, 'refusals_or_warnings'),
            $this->usageFromResponse($body),
        );
    }

    /**
     * @param array<string, mixed> $draft
     * @return list<DraftSentence>
     */
    private function sentencesFromDraft(array $draft): array
    {
        $sentences = [];
        foreach ($this->arrayFromDraft($draft, 'sentences') as $sentence) {
            $sentenceObject = $this->objectFromMixed($sentence, 'OpenAI draft sentence');
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
        foreach ($this->arrayFromDraft($draft, 'claims') as $claim) {
            $claimObject = $this->objectFromMixed($claim, 'OpenAI draft claim');
            $claims[] = new DraftClaim(
                $this->stringField($claimObject, 'text'),
                $this->stringField($claimObject, 'type'),
                $this->arrayFromDraft($claimObject, 'cited_source_ids'),
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
        $values = [];
        foreach ($this->arrayFromDraft($draft, $key) as $value) {
            if (!is_string($value)) {
                throw new DomainException(sprintf('OpenAI draft %s contained a non-string.', $key));
            }
            $values[] = $value;
        }

        return $values;
    }

    /**
     * @param array<string, mixed> $draft
     * @return list<mixed>
     */
    private function arrayFromDraft(array $draft, string $key): array
    {
        $value = $draft[$key] ?? null;
        if (!is_array($value)) {
            throw new DomainException(sprintf('OpenAI draft %s must be an array.', $key));
        }

        return array_values($value);
    }

    /** @param array<mixed> $source */
    private function stringField(array $source, string $key): string
    {
        $value = $source[$key] ?? null;
        if (!is_string($value) || trim($value) === '') {
            throw new DomainException(sprintf('OpenAI draft field %s must be a non-empty string.', $key));
        }

        return $value;
    }

    /** @return array<string, mixed> */
    private function jsonObject(string $json, string $label): array
    {
        $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);

        return $this->objectFromMixed($decoded, $label);
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

    /** @param array<string, mixed> $body */
    private function usageFromResponse(array $body): DraftUsage
    {
        $usage = $body['usage'] ?? [];
        $inputTokens = $this->intFromUsage($usage, 'prompt_tokens');
        $outputTokens = $this->intFromUsage($usage, 'completion_tokens');

        return new DraftUsage(
            $this->model,
            $inputTokens,
            $outputTokens,
            TokenCostEstimator::estimate(
                $inputTokens,
                $outputTokens,
                $this->inputCostPerMillionTokens,
                $this->outputCostPerMillionTokens,
            ),
        );
    }

    private function intFromUsage(mixed $usage, string $key): int
    {
        if (!is_array($usage) || !isset($usage[$key]) || !is_int($usage[$key])) {
            return 0;
        }

        return $usage[$key];
    }

}
