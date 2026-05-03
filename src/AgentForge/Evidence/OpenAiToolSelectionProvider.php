<?php

/**
 * OpenAI-backed structured chart-section selector.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\AgentForge\Evidence;

use DomainException;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;
use Psr\Http\Message\ResponseInterface;
use SensitiveParameter;

final readonly class OpenAiToolSelectionProvider implements ToolSelectionProvider
{
    public function __construct(
        private ClientInterface $client,
        #[SensitiveParameter] private string $apiKey,
        private string $model,
    ) {
        if (trim($apiKey) === '') {
            throw new DomainException('OpenAI tool selector requires an API key.');
        }
        if (trim($model) === '') {
            throw new DomainException('OpenAI tool selector requires a model.');
        }
    }

    public function select(ToolSelectionRequest $request): ToolSelectionResult
    {
        try {
            $response = $this->client->request('POST', '/v1/chat/completions', [
                'headers' => [
                    'Authorization' => sprintf('Bearer %s', $this->apiKey),
                    'Content-Type' => 'application/json',
                ],
                'json' => [
                    'model' => $this->model,
                    'messages' => [
                        ['role' => 'system', 'content' => self::systemPrompt()],
                        ['role' => 'user', 'content' => self::userMessage($request)],
                    ],
                    'response_format' => [
                        'type' => 'json_schema',
                        'json_schema' => [
                            'name' => 'agentforge_tool_selection',
                            'strict' => true,
                            'schema' => self::schema(),
                        ],
                    ],
                    'temperature' => 0,
                ],
            ]);
        } catch (GuzzleException $exception) {
            throw new ToolSelectionException('OpenAI tool selection request failed.', previous: $exception);
        }

        return self::parseOpenAiResponse($response);
    }

    public function mode(): string
    {
        return 'openai';
    }

    private static function parseOpenAiResponse(ResponseInterface $response): ToolSelectionResult
    {
        $body = self::jsonObject((string) $response->getBody(), 'OpenAI selector response');
        $choices = self::arrayField($body, 'choices');
        $first = $choices[0] ?? null;
        if (!is_array($first)) {
            throw new ToolSelectionException('OpenAI selector response did not include a choice.');
        }
        $message = self::objectField($first, 'message');
        $content = $message['content'] ?? null;
        if (!is_string($content) || trim($content) === '') {
            throw new ToolSelectionException('OpenAI selector response content was empty.');
        }

        return self::resultFromObject(self::jsonObject($content, 'OpenAI selector content'));
    }

    public static function systemPrompt(): string
    {
        return implode("\n", [
            'You select OpenEMR chart evidence sections for AgentForge.',
            'Return only JSON matching the schema.',
            'Select sections only for the current active patient.',
            'Do not answer the clinical question.',
            'Do not recommend diagnosis, treatment, dosing, medication changes, or note drafting.',
            'Prefer the smallest sufficient section set, but include all sections needed for the physician to safely double-check chart facts.',
        ]);
    }

    public static function userMessage(ToolSelectionRequest $request): string
    {
        return json_encode([
            'question' => $request->question->value,
            'scope_policy' => $request->scopePolicy,
            'conversation_summary' => $request->conversationSummary?->toPromptArray(),
            'allowed_sections' => $request->allowedSections,
            'question_type_guidance' => [
                'visit_briefing',
                'follow_up_change_review',
                'medication',
                'allergy',
                'lab',
                'vital',
                'last_plan',
                'problem',
                'missing_data',
            ],
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    }

    /** @return array<string, mixed> */
    public static function schema(): array
    {
        return [
            'type' => 'object',
            'additionalProperties' => false,
            'required' => ['question_type', 'sections'],
            'properties' => [
                'question_type' => ['type' => 'string', 'minLength' => 1],
                'sections' => [
                    'type' => 'array',
                    'items' => ['type' => 'string'],
                ],
            ],
        ];
    }

    /** @param array<string, mixed> $object */
    public static function resultFromObject(array $object): ToolSelectionResult
    {
        $questionType = $object['question_type'] ?? null;
        if (!is_string($questionType) || trim($questionType) === '') {
            throw new ToolSelectionException('Selector question_type was empty.');
        }

        $sections = [];
        foreach (self::arrayField($object, 'sections') as $section) {
            if (is_string($section) && trim($section) !== '') {
                $sections[] = $section;
            }
        }

        return new ToolSelectionResult($questionType, $sections);
    }

    /** @return array<string, mixed> */
    private static function jsonObject(string $json, string $label): array
    {
        try {
            $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new ToolSelectionException(sprintf('%s was not valid JSON.', $label), previous: $exception);
        }
        if (!is_array($decoded)) {
            throw new ToolSelectionException(sprintf('%s was not an object.', $label));
        }

        return $decoded;
    }

    /** @param array<string, mixed> $object @return list<mixed> */
    private static function arrayField(array $object, string $field): array
    {
        $value = $object[$field] ?? null;
        if (!is_array($value)) {
            throw new ToolSelectionException(sprintf('Selector field %s must be an array.', $field));
        }

        return array_values($value);
    }

    /** @param array<string, mixed> $object @return array<string, mixed> */
    private static function objectField(array $object, string $field): array
    {
        $value = $object[$field] ?? null;
        if (!is_array($value)) {
            throw new ToolSelectionException(sprintf('Selector field %s must be an object.', $field));
        }

        return $value;
    }
}
