<?php

/**
 * Extraction provider selection for AgentForge clinical documents.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\AgentForge\Document\Extraction;

use DomainException;
use OpenEMR\AgentForge\Llm\ProviderCostCatalog;
use SensitiveParameter;

final readonly class ExtractionProviderConfig
{
    /** @deprecated Use {@see ExtractionProviderMode::Fixture} instead. */
    public const MODE_FIXTURE = 'fixture';
    /** @deprecated Use {@see ExtractionProviderMode::OpenAi} instead. */
    public const MODE_OPENAI = 'openai';

    public string $mode;
    public ?string $apiKey;
    public string $model;
    public float $timeoutSeconds;
    public float $connectTimeoutSeconds;
    public ?float $inputCostPerMillionTokens;
    public ?float $outputCostPerMillionTokens;

    public function __construct(
        string $mode = ExtractionProviderMode::Fixture->value,
        #[SensitiveParameter] ?string $apiKey = null,
        ?string $model = null,
        public ?string $fixtureManifestPath = null,
        ?float $inputCostPerMillionTokens = null,
        ?float $outputCostPerMillionTokens = null,
        float $timeoutSeconds = 60.0,
        float $connectTimeoutSeconds = 10.0,
        public int $maxPdfPages = 5,
    ) {
        if ($mode === '') {
            throw new DomainException('Extraction provider mode is required.');
        }
        if ($mode === ExtractionProviderMode::OpenAi->value && trim((string) $apiKey) === '') {
            throw new DomainException('OpenAI extraction provider requires an API key.');
        }
        if ($timeoutSeconds <= 0.0) {
            throw new DomainException('Extraction provider timeout must be greater than zero.');
        }
        if ($connectTimeoutSeconds <= 0.0) {
            throw new DomainException('Extraction provider connect timeout must be greater than zero.');
        }
        if ($maxPdfPages < 1) {
            throw new DomainException('Extraction provider max PDF pages must be positive.');
        }

        $resolvedModel = $model ?? self::defaultModel($mode);
        if (trim($resolvedModel) === '') {
            throw new DomainException('Extraction provider model is required.');
        }

        $defaultCosts = ProviderCostCatalog::lookup($resolvedModel);

        $this->mode = $mode;
        $this->apiKey = $apiKey;
        $this->model = $resolvedModel;
        $this->timeoutSeconds = $timeoutSeconds;
        $this->connectTimeoutSeconds = $connectTimeoutSeconds;
        $this->inputCostPerMillionTokens = $inputCostPerMillionTokens ?? $defaultCosts->inputCostPerMillionTokens;
        $this->outputCostPerMillionTokens = $outputCostPerMillionTokens ?? $defaultCosts->outputCostPerMillionTokens;
    }

    public static function fixture(?string $manifestPath = null): self
    {
        return new self(ExtractionProviderMode::Fixture->value, fixtureManifestPath: $manifestPath);
    }

    public static function fromEnvironment(): self
    {
        $explicitMode = self::envString('AGENTFORGE_VLM_PROVIDER');
        $openAiKey = self::envString('AGENTFORGE_OPENAI_API_KEY') ?? self::envString('OPENAI_API_KEY');
        $mode = $explicitMode ?? ($openAiKey === null ? ExtractionProviderMode::Fixture->value : ExtractionProviderMode::OpenAi->value);

        return new self(
            mode: $mode,
            apiKey: $openAiKey,
            model: self::envString('AGENTFORGE_VLM_MODEL'),
            fixtureManifestPath: self::fixtureManifestPathFromEnvironment(),
            inputCostPerMillionTokens: self::envFloat('AGENTFORGE_VLM_INPUT_COST_PER_1M'),
            outputCostPerMillionTokens: self::envFloat('AGENTFORGE_VLM_OUTPUT_COST_PER_1M'),
            timeoutSeconds: self::envFloat('AGENTFORGE_VLM_TIMEOUT_SECONDS') ?? 60.0,
            connectTimeoutSeconds: self::envFloat('AGENTFORGE_VLM_CONNECT_TIMEOUT_SECONDS') ?? 10.0,
            maxPdfPages: self::envInt('AGENTFORGE_VLM_MAX_PAGES') ?? 5,
        );
    }

    private static function defaultModel(string $mode): string
    {
        return match ($mode) {
            ExtractionProviderMode::OpenAi->value => 'gpt-4o',
            default => 'fixture',
        };
    }

    private static function fixtureManifestPathFromEnvironment(): ?string
    {
        $manifest = self::envString('AGENTFORGE_EXTRACTION_FIXTURE_MANIFEST');
        if ($manifest !== null) {
            return $manifest;
        }

        $dir = self::envString('AGENTFORGE_EXTRACTION_FIXTURES_DIR');
        if ($dir === null) {
            return null;
        }

        return rtrim($dir, '/') . '/manifest.json';
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

    private static function envInt(string $name): ?int
    {
        $value = self::envString($name);
        if ($value === null || !ctype_digit($value)) {
            return null;
        }

        return (int) $value;
    }
}
