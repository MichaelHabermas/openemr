<?php

/**
 * Draft provider selection for AgentForge.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\AgentForge\ResponseGeneration;

use DomainException;
use OpenEMR\AgentForge\AgentForgeEnv;
use OpenEMR\AgentForge\Llm\ProviderCostCatalog;

final readonly class DraftProviderConfig
{
    public string $mode;
    public ?string $apiKey;
    public string $model;
    public ?float $inputCostPerMillionTokens;
    public ?float $outputCostPerMillionTokens;
    public float $timeoutSeconds;
    public float $connectTimeoutSeconds;

    public function __construct(
        string $mode = DraftProviderMode::Fixture->value,
        #[\SensitiveParameter] ?string $apiKey = null,
        ?string $model = null,
        ?float $inputCostPerMillionTokens = null,
        ?float $outputCostPerMillionTokens = null,
        public ?float $cacheWriteCostPerMillionTokens = null,
        public ?float $cacheReadCostPerMillionTokens = null,
        float $timeoutSeconds = 15.0,
        float $connectTimeoutSeconds = 5.0,
    ) {
        if ($mode === '') {
            throw new DomainException('Draft provider mode is required.');
        }
        if ($mode === DraftProviderMode::OpenAi->value && trim((string) $apiKey) === '') {
            throw new DomainException('OpenAI draft provider requires an API key.');
        }
        if ($mode === DraftProviderMode::Anthropic->value && trim((string) $apiKey) === '') {
            throw new DomainException('Anthropic draft provider requires an API key.');
        }
        if ($timeoutSeconds <= 0.0) {
            throw new DomainException('Draft provider timeout must be greater than zero.');
        }
        if ($connectTimeoutSeconds <= 0.0) {
            throw new DomainException('Draft provider connect timeout must be greater than zero.');
        }

        $resolvedModel = $model ?? self::defaultModel($mode);
        if (trim($resolvedModel) === '') {
            throw new DomainException('Draft provider model is required.');
        }

        $defaultCosts = ProviderCostCatalog::lookup($resolvedModel);

        $this->mode = $mode;
        $this->apiKey = $apiKey;
        $this->model = $resolvedModel;
        $this->inputCostPerMillionTokens = $inputCostPerMillionTokens ?? $defaultCosts->inputCostPerMillionTokens;
        $this->outputCostPerMillionTokens = $outputCostPerMillionTokens ?? $defaultCosts->outputCostPerMillionTokens;
        $this->timeoutSeconds = $timeoutSeconds;
        $this->connectTimeoutSeconds = $connectTimeoutSeconds;
    }

    public static function fixture(): self
    {
        return new self(DraftProviderMode::Fixture->value);
    }

    public static function fromEnvironment(): self
    {
        $explicitMode = AgentForgeEnv::string('AGENTFORGE_DRAFT_PROVIDER');
        $openAiKey = AgentForgeEnv::string('AGENTFORGE_OPENAI_API_KEY') ?? AgentForgeEnv::string('OPENAI_API_KEY');
        $anthropicKey = AgentForgeEnv::string('AGENTFORGE_ANTHROPIC_API_KEY') ?? AgentForgeEnv::string('ANTHROPIC_API_KEY');

        $mode = $explicitMode ?? match (true) {
            $anthropicKey !== null => DraftProviderMode::Anthropic->value,
            $openAiKey !== null => DraftProviderMode::OpenAi->value,
            default => DraftProviderMode::Fixture->value,
        };

        if ($mode === DraftProviderMode::Anthropic->value) {
            return new self(
                mode: $mode,
                apiKey: $anthropicKey,
                model: AgentForgeEnv::string('AGENTFORGE_ANTHROPIC_MODEL'),
                inputCostPerMillionTokens: AgentForgeEnv::float('AGENTFORGE_ANTHROPIC_INPUT_COST_PER_1M'),
                outputCostPerMillionTokens: AgentForgeEnv::float('AGENTFORGE_ANTHROPIC_OUTPUT_COST_PER_1M'),
                cacheWriteCostPerMillionTokens: AgentForgeEnv::float('AGENTFORGE_ANTHROPIC_CACHE_WRITE_COST_PER_1M'),
                cacheReadCostPerMillionTokens: AgentForgeEnv::float('AGENTFORGE_ANTHROPIC_CACHE_READ_COST_PER_1M'),
                timeoutSeconds: AgentForgeEnv::float('AGENTFORGE_ANTHROPIC_TIMEOUT_SECONDS') ?? 15.0,
                connectTimeoutSeconds: AgentForgeEnv::float('AGENTFORGE_ANTHROPIC_CONNECT_TIMEOUT_SECONDS') ?? 5.0,
            );
        }

        return new self(
            mode: $mode,
            apiKey: $openAiKey,
            model: AgentForgeEnv::string('AGENTFORGE_OPENAI_MODEL'),
            inputCostPerMillionTokens: AgentForgeEnv::float('AGENTFORGE_OPENAI_INPUT_COST_PER_1M'),
            outputCostPerMillionTokens: AgentForgeEnv::float('AGENTFORGE_OPENAI_OUTPUT_COST_PER_1M'),
            timeoutSeconds: AgentForgeEnv::float('AGENTFORGE_OPENAI_TIMEOUT_SECONDS') ?? 15.0,
            connectTimeoutSeconds: AgentForgeEnv::float('AGENTFORGE_OPENAI_CONNECT_TIMEOUT_SECONDS') ?? 5.0,
        );
    }

    private static function defaultModel(string $mode): string
    {
        return match ($mode) {
            DraftProviderMode::Anthropic->value => 'claude-haiku-4-5-20251001',
            default => 'gpt-4o-mini',
        };
    }
}
