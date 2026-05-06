<?php

/**
 * Anthropic-backed structured chart-section selector.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\AgentForge\Evidence;

use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;
use OpenEMR\AgentForge\Llm\LlmCredentialGuard;
use Psr\Http\Message\ResponseInterface;
use SensitiveParameter;

final readonly class AnthropicToolSelectionProvider implements ToolSelectionProvider
{
    private const ANTHROPIC_VERSION = '2023-06-01';
    private const TOOL_NAME = 'agentforge_tool_selection';

    public function __construct(
        private ClientInterface $client,
        #[SensitiveParameter] private string $apiKey,
        private string $model,
    ) {
        LlmCredentialGuard::requireApiKey($apiKey, 'Anthropic tool selector');
        LlmCredentialGuard::requireModel($model, 'Anthropic tool selector');
    }

    public function select(ToolSelectionRequest $request): ToolSelectionResult
    {
        try {
            $response = $this->client->request('POST', '/v1/messages', [
                'headers' => [
                    'x-api-key' => $this->apiKey,
                    'anthropic-version' => self::ANTHROPIC_VERSION,
                    'content-type' => 'application/json',
                ],
                'json' => [
                    'model' => $this->model,
                    'max_tokens' => 512,
                    'temperature' => 0,
                    'system' => OpenAiToolSelectionProvider::systemPrompt(),
                    'tools' => [[
                        'name' => self::TOOL_NAME,
                        'description' => 'Select bounded AgentForge chart evidence sections.',
                        'input_schema' => OpenAiToolSelectionProvider::schema(),
                    ]],
                    'tool_choice' => ['type' => 'tool', 'name' => self::TOOL_NAME],
                    'messages' => [[
                        'role' => 'user',
                        'content' => OpenAiToolSelectionProvider::userMessage($request),
                    ]],
                ],
            ]);
        } catch (GuzzleException $exception) {
            throw new ToolSelectionException('Anthropic tool selection request failed.', previous: $exception);
        }

        return self::parseAnthropicResponse($response);
    }

    public function mode(): string
    {
        return 'anthropic';
    }

    private static function parseAnthropicResponse(ResponseInterface $response): ToolSelectionResult
    {
        try {
            $decoded = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new ToolSelectionException('Anthropic selector response was not valid JSON.', previous: $exception);
        }
        if (!is_array($decoded)) {
            throw new ToolSelectionException('Anthropic selector response was not an object.');
        }
        $body = OpenAiToolSelectionProvider::stringKeyedObject($decoded, 'Anthropic selector response');
        if (!is_array($body['content'] ?? null)) {
            throw new ToolSelectionException('Anthropic selector response did not include content.');
        }

        foreach ($body['content'] as $block) {
            if (
                is_array($block)
                && ($block['type'] ?? null) === 'tool_use'
                && ($block['name'] ?? null) === self::TOOL_NAME
                && is_array($block['input'] ?? null)
            ) {
                return OpenAiToolSelectionProvider::resultFromObject(
                    OpenAiToolSelectionProvider::stringKeyedObject($block['input'], 'Anthropic selector tool input'),
                );
            }
        }

        throw new ToolSelectionException('Anthropic selector response did not include tool output.');
    }
}
