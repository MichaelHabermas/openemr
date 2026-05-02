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

final readonly class DraftProviderConfig
{
    public const MODE_FIXTURE = 'fixture';
    public const MODE_DISABLED = 'disabled';
    public const MODE_OPENAI = 'openai';
    public const MODE_ANTHROPIC = 'anthropic';

    public string $mode;
    public ?string $apiKey;
    public string $model;
    public ?float $inputCostPerMillionTokens;
    public ?float $outputCostPerMillionTokens;
    public float $timeoutSeconds;
    public float $connectTimeoutSeconds;

    public function __construct(
        string $mode = self::MODE_FIXTURE,
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
        if ($mode === self::MODE_OPENAI && trim((string) $apiKey) === '') {
            throw new DomainException('OpenAI draft provider requires an API key.');
        }
        if ($mode === self::MODE_ANTHROPIC && trim((string) $apiKey) === '') {
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

        $this->mode = $mode;
        $this->apiKey = $apiKey;
        $this->model = $resolvedModel;
        $this->inputCostPerMillionTokens = $inputCostPerMillionTokens ?? self::defaultInputCost($resolvedModel);
        $this->outputCostPerMillionTokens = $outputCostPerMillionTokens ?? self::defaultOutputCost($resolvedModel);
        $this->timeoutSeconds = $timeoutSeconds;
        $this->connectTimeoutSeconds = $connectTimeoutSeconds;
    }

    public static function fixture(): self
    {
        return new self(self::MODE_FIXTURE);
    }

    public static function fromEnvironment(): self
    {
        $explicitMode = self::envString('AGENTFORGE_DRAFT_PROVIDER');
        $openAiKey = self::envString('AGENTFORGE_OPENAI_API_KEY') ?? self::envString('OPENAI_API_KEY');
        $anthropicKey = self::envString('AGENTFORGE_ANTHROPIC_API_KEY') ?? self::envString('ANTHROPIC_API_KEY');

        $mode = $explicitMode ?? match (true) {
            $anthropicKey !== null => self::MODE_ANTHROPIC,
            $openAiKey !== null => self::MODE_OPENAI,
            default => self::MODE_FIXTURE,
        };

        if ($mode === self::MODE_ANTHROPIC) {
            return new self(
                mode: $mode,
                apiKey: $anthropicKey,
                model: self::envString('AGENTFORGE_ANTHROPIC_MODEL'),
                inputCostPerMillionTokens: self::envFloat('AGENTFORGE_ANTHROPIC_INPUT_COST_PER_1M'),
                outputCostPerMillionTokens: self::envFloat('AGENTFORGE_ANTHROPIC_OUTPUT_COST_PER_1M'),
                cacheWriteCostPerMillionTokens: self::envFloat('AGENTFORGE_ANTHROPIC_CACHE_WRITE_COST_PER_1M'),
                cacheReadCostPerMillionTokens: self::envFloat('AGENTFORGE_ANTHROPIC_CACHE_READ_COST_PER_1M'),
                timeoutSeconds: self::envFloat('AGENTFORGE_ANTHROPIC_TIMEOUT_SECONDS') ?? 15.0,
                connectTimeoutSeconds: self::envFloat('AGENTFORGE_ANTHROPIC_CONNECT_TIMEOUT_SECONDS') ?? 5.0,
            );
        }

        return new self(
            mode: $mode,
            apiKey: $openAiKey,
            model: self::envString('AGENTFORGE_OPENAI_MODEL'),
            inputCostPerMillionTokens: self::envFloat('AGENTFORGE_OPENAI_INPUT_COST_PER_1M'),
            outputCostPerMillionTokens: self::envFloat('AGENTFORGE_OPENAI_OUTPUT_COST_PER_1M'),
            timeoutSeconds: self::envFloat('AGENTFORGE_OPENAI_TIMEOUT_SECONDS') ?? 15.0,
            connectTimeoutSeconds: self::envFloat('AGENTFORGE_OPENAI_CONNECT_TIMEOUT_SECONDS') ?? 5.0,
        );
    }

    private static function defaultModel(string $mode): string
    {
        return match ($mode) {
            self::MODE_ANTHROPIC => 'claude-haiku-4-5-20251001',
            default => 'gpt-4o-mini',
        };
    }

    private static function defaultInputCost(string $model): ?float
    {
        return match ($model) {
            'gpt-4o-mini' => 0.15,
            'claude-haiku-4-5-20251001' => 1.00,
            default => null,
        };
    }

    private static function defaultOutputCost(string $model): ?float
    {
        return match ($model) {
            'gpt-4o-mini' => 0.60,
            'claude-haiku-4-5-20251001' => 5.00,
            default => null,
        };
    }

    private static function envString(string $name): ?string
    {
        $value = getenv($name, true);
        if (!is_string($value) || trim($value) === '') {
            return null;
        }

        return $value;
    }

    private static function envFloat(string $name): ?float
    {
        $value = self::envString($name);
        if ($value === null || !is_numeric($value)) {
            return null;
        }

        return (float) $value;
    }
}
