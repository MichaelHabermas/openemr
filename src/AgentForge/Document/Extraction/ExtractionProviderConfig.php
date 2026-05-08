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
use OpenEMR\AgentForge\AgentForgeEnv;
use OpenEMR\AgentForge\Llm\ProviderCostCatalog;
use SensitiveParameter;

final readonly class ExtractionProviderConfig
{
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
        public int $maxTiffSourceBytes = 10_485_760,
        public int $maxDocxSourceBytes = 10_485_760,
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
        if ($maxTiffSourceBytes < 1) {
            throw new DomainException('Extraction provider max TIFF source bytes must be positive.');
        }
        if ($maxDocxSourceBytes < 1) {
            throw new DomainException('Extraction provider max DOCX source bytes must be positive.');
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
        $explicitMode = AgentForgeEnv::string('AGENTFORGE_VLM_PROVIDER');
        $openAiKey = AgentForgeEnv::string('AGENTFORGE_OPENAI_API_KEY') ?? AgentForgeEnv::string('OPENAI_API_KEY');
        $mode = $explicitMode ?? ($openAiKey === null ? ExtractionProviderMode::Fixture->value : ExtractionProviderMode::OpenAi->value);

        return new self(
            mode: $mode,
            apiKey: $openAiKey,
            model: AgentForgeEnv::string('AGENTFORGE_VLM_MODEL'),
            fixtureManifestPath: self::fixtureManifestPathFromEnvironment(),
            inputCostPerMillionTokens: AgentForgeEnv::float('AGENTFORGE_VLM_INPUT_COST_PER_1M'),
            outputCostPerMillionTokens: AgentForgeEnv::float('AGENTFORGE_VLM_OUTPUT_COST_PER_1M'),
            timeoutSeconds: AgentForgeEnv::float('AGENTFORGE_VLM_TIMEOUT_SECONDS') ?? 60.0,
            connectTimeoutSeconds: AgentForgeEnv::float('AGENTFORGE_VLM_CONNECT_TIMEOUT_SECONDS') ?? 10.0,
            maxPdfPages: AgentForgeEnv::int('AGENTFORGE_VLM_MAX_PAGES') ?? 5,
            maxTiffSourceBytes: AgentForgeEnv::int('AGENTFORGE_VLM_MAX_TIFF_BYTES') ?? 10_485_760,
            maxDocxSourceBytes: AgentForgeEnv::int('AGENTFORGE_VLM_MAX_DOCX_BYTES') ?? 10_485_760,
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
        $manifest = AgentForgeEnv::string('AGENTFORGE_EXTRACTION_FIXTURE_MANIFEST');
        if ($manifest !== null) {
            return $manifest;
        }

        $dir = AgentForgeEnv::string('AGENTFORGE_EXTRACTION_FIXTURES_DIR');
        if ($dir === null) {
            return null;
        }

        return rtrim($dir, '/') . '/manifest.json';
    }
}
