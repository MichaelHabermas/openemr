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

    public function __construct(
        public string $mode = self::MODE_FIXTURE,
        #[\SensitiveParameter] public ?string $apiKey = null,
        public string $model = 'gpt-4o-mini',
        public ?float $inputCostPerMillionTokens = null,
        public ?float $outputCostPerMillionTokens = null,
    ) {
        if ($this->mode === '') {
            throw new DomainException('Draft provider mode is required.');
        }
        if ($this->mode === self::MODE_OPENAI && trim((string) $this->apiKey) === '') {
            throw new DomainException('OpenAI draft provider requires an API key.');
        }
        if (trim($this->model) === '') {
            throw new DomainException('Draft provider model is required.');
        }
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
        );
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
