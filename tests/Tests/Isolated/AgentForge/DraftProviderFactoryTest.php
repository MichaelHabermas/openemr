<?php

/**
 * Isolated tests for AgentForge draft provider selection.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Tests\Isolated\AgentForge;

use OpenEMR\AgentForge\ResponseGeneration\AnthropicDraftProvider;
use OpenEMR\AgentForge\ResponseGeneration\DisabledDraftProvider;
use OpenEMR\AgentForge\ResponseGeneration\DraftProviderConfig;
use OpenEMR\AgentForge\ResponseGeneration\DraftProviderFactory;
use OpenEMR\AgentForge\ResponseGeneration\FixtureDraftProvider;
use OpenEMR\AgentForge\ResponseGeneration\OpenAiDraftProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class DraftProviderFactoryTest extends TestCase
{
    /** @var array<string, string|false> */
    private array $providerEnv = [];

    protected function setUp(): void
    {
        parent::setUp();

        foreach ($this->providerEnvNames() as $name) {
            $this->providerEnv[$name] = getenv($name, true);
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->providerEnv as $name => $value) {
            if ($value === false) {
                putenv($name);
                continue;
            }

            putenv($name . '=' . $value);
        }

        $this->providerEnv = [];

        parent::tearDown();
    }

    public function testDefaultProviderIsFixtureFirst(): void
    {
        foreach ($this->providerEnvNames() as $name) {
            putenv($name);
        }

        $this->assertInstanceOf(FixtureDraftProvider::class, DraftProviderFactory::createDefault());
    }

    public function testFixtureModeReturnsFixtureProvider(): void
    {
        $provider = DraftProviderFactory::create(new DraftProviderConfig(DraftProviderConfig::MODE_FIXTURE));

        $this->assertInstanceOf(FixtureDraftProvider::class, $provider);
    }

    public function testDisabledModeReturnsFailClosedProvider(): void
    {
        $provider = DraftProviderFactory::create(new DraftProviderConfig(DraftProviderConfig::MODE_DISABLED));

        $this->assertInstanceOf(DisabledDraftProvider::class, $provider);
    }

    public function testOpenAiModeReturnsOpenAiProvider(): void
    {
        $provider = DraftProviderFactory::create(new DraftProviderConfig(
            mode: DraftProviderConfig::MODE_OPENAI,
            apiKey: 'test-key',
            model: 'gpt-4o-mini',
        ));

        $this->assertInstanceOf(OpenAiDraftProvider::class, $provider);
    }

    public function testOpenAiDefaultsUseKnownGpt4oMiniPricing(): void
    {
        $config = new DraftProviderConfig(
            mode: DraftProviderConfig::MODE_OPENAI,
            apiKey: 'test-key',
        );

        $this->assertSame(0.15, $config->inputCostPerMillionTokens);
        $this->assertSame(0.60, $config->outputCostPerMillionTokens);
        $this->assertSame(15.0, $config->timeoutSeconds);
        $this->assertSame(5.0, $config->connectTimeoutSeconds);
    }

    public function testNonDefaultModelDoesNotInheritGpt4oMiniPricing(): void
    {
        $config = new DraftProviderConfig(
            mode: DraftProviderConfig::MODE_OPENAI,
            apiKey: 'test-key',
            model: 'gpt-4o',
            inputCostPerMillionTokens: null,
            outputCostPerMillionTokens: null,
        );

        $this->assertNull($config->inputCostPerMillionTokens);
        $this->assertNull($config->outputCostPerMillionTokens);
    }

    public function testAnthropicModeReturnsAnthropicProvider(): void
    {
        $provider = DraftProviderFactory::create(new DraftProviderConfig(
            mode: DraftProviderConfig::MODE_ANTHROPIC,
            apiKey: 'test-anthropic-key',
        ));

        $this->assertInstanceOf(AnthropicDraftProvider::class, $provider);
    }

    public function testAnthropicDefaultsUseHaiku45Pricing(): void
    {
        $config = new DraftProviderConfig(
            mode: DraftProviderConfig::MODE_ANTHROPIC,
            apiKey: 'test-anthropic-key',
        );

        $this->assertSame('claude-haiku-4-5-20251001', $config->model);
        $this->assertSame(1.00, $config->inputCostPerMillionTokens);
        $this->assertSame(5.00, $config->outputCostPerMillionTokens);
        $this->assertNull($config->cacheWriteCostPerMillionTokens);
        $this->assertNull($config->cacheReadCostPerMillionTokens);
    }

    public function testUnsupportedExternalModeFailsClosed(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('not configured');

        DraftProviderFactory::create(new DraftProviderConfig('external-model'));
    }

    /**
     * @return list<string>
     */
    private function providerEnvNames(): array
    {
        return [
            'AGENTFORGE_DRAFT_PROVIDER',
            'AGENTFORGE_OPENAI_API_KEY',
            'OPENAI_API_KEY',
            'AGENTFORGE_ANTHROPIC_API_KEY',
            'ANTHROPIC_API_KEY',
        ];
    }
}
