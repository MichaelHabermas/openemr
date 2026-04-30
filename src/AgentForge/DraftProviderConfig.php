<?php

/**
 * Draft provider selection for AgentForge.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\AgentForge;

use DomainException;

final readonly class DraftProviderConfig
{
    public const MODE_FIXTURE = 'fixture';
    public const MODE_DISABLED = 'disabled';
    public const MODE_OPENAI = 'openai';

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
        string $model = 'gpt-4o-mini',
        ?float $inputCostPerMillionTokens = null,
        ?float $outputCostPerMillionTokens = null,
        float $timeoutSeconds = 15.0,
        float $connectTimeoutSeconds = 5.0,
    ) {
        if ($mode === '') {
            throw new DomainException('Draft provider mode is required.');
        }
        if ($mode === self::MODE_OPENAI && trim((string) $apiKey) === '') {
            throw new DomainException('OpenAI draft provider requires an API key.');
        }
        if (trim($model) === '') {
            throw new DomainException('Draft provider model is required.');
        }
        if ($timeoutSeconds <= 0.0) {
            throw new DomainException('Draft provider timeout must be greater than zero.');
        }
        if ($connectTimeoutSeconds <= 0.0) {
            throw new DomainException('Draft provider connect timeout must be greater than zero.');
        }

        $this->mode = $mode;
        $this->apiKey = $apiKey;
        $this->model = $model;
        $this->inputCostPerMillionTokens = $inputCostPerMillionTokens ?? self::defaultInputCost($model);
        $this->outputCostPerMillionTokens = $outputCostPerMillionTokens ?? self::defaultOutputCost($model);
        $this->timeoutSeconds = $timeoutSeconds;
        $this->connectTimeoutSeconds = $connectTimeoutSeconds;
    }

    public static function fixture(): self
    {
        return new self(self::MODE_FIXTURE);
    }

    public static function fromEnvironment(): self
    {
        $apiKey = self::envString('AGENTFORGE_OPENAI_API_KEY') ?? self::envString('OPENAI_API_KEY');
        $mode = self::envString('AGENTFORGE_DRAFT_PROVIDER')
            ?? ($apiKey === null ? self::MODE_FIXTURE : self::MODE_OPENAI);

        return new self(
            mode: $mode,
            apiKey: $apiKey,
            model: self::envString('AGENTFORGE_OPENAI_MODEL') ?? 'gpt-4o-mini',
            inputCostPerMillionTokens: self::envFloat('AGENTFORGE_OPENAI_INPUT_COST_PER_1M'),
            outputCostPerMillionTokens: self::envFloat('AGENTFORGE_OPENAI_OUTPUT_COST_PER_1M'),
            timeoutSeconds: self::envFloat('AGENTFORGE_OPENAI_TIMEOUT_SECONDS') ?? 15.0,
            connectTimeoutSeconds: self::envFloat('AGENTFORGE_OPENAI_CONNECT_TIMEOUT_SECONDS') ?? 5.0,
        );
    }

    private static function defaultInputCost(string $model): ?float
    {
        return $model === 'gpt-4o-mini' ? 0.15 : null;
    }

    private static function defaultOutputCost(string $model): ?float
    {
        return $model === 'gpt-4o-mini' ? 0.60 : null;
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
