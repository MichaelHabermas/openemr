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

use OpenEMR\AgentForge\DisabledDraftProvider;
use OpenEMR\AgentForge\DraftProviderConfig;
use OpenEMR\AgentForge\DraftProviderFactory;
use OpenEMR\AgentForge\FixtureDraftProvider;
use OpenEMR\AgentForge\OpenAiDraftProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class DraftProviderFactoryTest extends TestCase
{
    public function testDefaultProviderIsFixtureFirst(): void
    {
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

    public function testUnsupportedExternalModeFailsClosed(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('not configured');

        DraftProviderFactory::create(new DraftProviderConfig('external-model'));
    }
}
