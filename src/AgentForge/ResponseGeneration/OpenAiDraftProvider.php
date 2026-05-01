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
use GuzzleHttp\Exception\GuzzleException;
use OpenEMR\AgentForge\Evidence\EvidenceBundle;
use OpenEMR\AgentForge\Handlers\AgentRequest;
use Psr\Http\Message\ResponseInterface;
use SensitiveParameter;

final readonly class OpenAiDraftProvider implements DraftProvider
{
    public function __construct(
        private ClientInterface $client,
        #[SensitiveParameter] private string $apiKey,
        private string $model,
        private ?float $inputCostPerMillionTokens = null,
        private ?float $outputCostPerMillionTokens = null,
    ) {
        if (trim($apiKey) === '') {
            throw new DomainException('OpenAI draft provider requires an API key.');
        }
        if (trim($model) === '') {
            throw new DomainException('OpenAI draft provider requires a model.');
        }
    }

    public function draft(AgentRequest $request, EvidenceBundle $bundle): DraftResponse
    {
        try {
            $response = $this->client->request('POST', '/v1/chat/completions', [
                'headers' => [
                    'Authorization' => sprintf('Bearer %s', $this->apiKey),
                    'Content-Type' => 'application/json',
                ],
                'json' => $this->payload($request, $bundle),
            ]);
        } catch (GuzzleException $exception) {
            throw new DraftProviderException('OpenAI draft request failed.', previous: $exception);
        }

        return $this->parseResponse($response);
    }

    /** @return array<string, mixed> */
    private function payload(AgentRequest $request, EvidenceBundle $bundle): array
    {
        return [
            'model' => $this->model,
            'messages' => [
                [
                    'role' => 'system',
                    'content' => implode("\n", [
                        'You are AgentForge Clinical Co-Pilot inside OpenEMR.',
                        'Use only the supplied bounded evidence JSON.',
                        'Answer only the clinician question that was asked; do not add demographics, problems, medications, labs, or plan details unless they directly answer that question.',
                        'Do not diagnose, recommend treatment, suggest dosing, recommend medication changes, draft notes, or answer generic medical questions.',
                        'Every patient-specific fact must cite source IDs exactly as provided.',
                        'For every patient_fact claim, copy the cited evidence display_label and value exactly into the claim text.',
                        'If a sentence cites multiple sources, include every cited display_label and value in that sentence or split it into separate sentences.',
                        'If evidence is missing, say it was not found in the chart.',
                        'Return only valid JSON matching the response schema.',
                    ]),
                ],
                [
                    'role' => 'user',
                    'content' => json_encode([
                        'question' => $request->question->value,
                        'patient_id' => $request->patientId->value,
                        'bounded_evidence' => $bundle->toPromptArray(),
                    ], JSON_THROW_ON_ERROR),
                ],
            ],
            'response_format' => [
                'type' => 'json_schema',
                'json_schema' => [
                    'name' => 'agentforge_draft_response',
                    'strict' => true,
                    'schema' => $this->schema(),
                ],
            ],
            'temperature' => 0,
        ];
    }

    /** @return array<string, mixed> */
    private function schema(): array
    {
        return [
            'type' => 'object',
            'additionalProperties' => false,
            'required' => ['sentences', 'claims', 'missing_sections', 'refusals_or_warnings'],
            'properties' => [
                'sentences' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'additionalProperties' => false,
                        'required' => ['id', 'text'],
                        'properties' => [
                            'id' => ['type' => 'string'],
                            'text' => ['type' => 'string'],
                        ],
                    ],
                ],
                'claims' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'additionalProperties' => false,
                        'required' => ['text', 'type', 'cited_source_ids', 'sentence_id'],
                        'properties' => [
                            'text' => ['type' => 'string'],
                            'type' => [
                                'type' => 'string',
                                'enum' => [
                                    DraftClaim::TYPE_PATIENT_FACT,
                                    DraftClaim::TYPE_MISSING_DATA,
                                    DraftClaim::TYPE_REFUSAL,
                                    DraftClaim::TYPE_WARNING,
                                ],
                            ],
                            'cited_source_ids' => [
                                'type' => 'array',
                                'items' => ['type' => 'string'],
                            ],
                            'sentence_id' => ['type' => 'string'],
                        ],
                    ],
                ],
                'missing_sections' => [
                    'type' => 'array',
                    'items' => ['type' => 'string'],
                ],
                'refusals_or_warnings' => [
                    'type' => 'array',
                    'items' => ['type' => 'string'],
                ],
            ],
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
            $this->estimatedCost($inputTokens, $outputTokens),
        );
    }

    private function intFromUsage(mixed $usage, string $key): int
    {
        if (!is_array($usage) || !isset($usage[$key]) || !is_int($usage[$key])) {
            return 0;
        }

        return $usage[$key];
    }

    private function estimatedCost(int $inputTokens, int $outputTokens): ?float
    {
        if ($this->inputCostPerMillionTokens === null || $this->outputCostPerMillionTokens === null) {
            return null;
        }

        return (($inputTokens / 1_000_000) * $this->inputCostPerMillionTokens)
            + (($outputTokens / 1_000_000) * $this->outputCostPerMillionTokens);
    }
}
