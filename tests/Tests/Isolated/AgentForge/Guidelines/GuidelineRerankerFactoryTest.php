<?php

/**
 * Isolated tests for AgentForge guideline reranker configuration.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Tests\Isolated\AgentForge\Guidelines;

use OpenEMR\AgentForge\Guidelines\CohereReranker;
use OpenEMR\AgentForge\Guidelines\DeterministicReranker;
use OpenEMR\AgentForge\Guidelines\GuidelineRerankerFactory;
use PHPUnit\Framework\TestCase;

final class GuidelineRerankerFactoryTest extends TestCase
{
    private mixed $previousApiKey;

    protected function setUp(): void
    {
        $this->previousApiKey = getenv('AGENTFORGE_COHERE_API_KEY');
    }

    protected function tearDown(): void
    {
        if (is_string($this->previousApiKey)) {
            putenv('AGENTFORGE_COHERE_API_KEY=' . $this->previousApiKey);
            return;
        }

        putenv('AGENTFORGE_COHERE_API_KEY');
    }

    public function testDefaultsToDeterministicRerankerWithoutCohereKey(): void
    {
        putenv('AGENTFORGE_COHERE_API_KEY');

        $this->assertInstanceOf(DeterministicReranker::class, GuidelineRerankerFactory::createDefault());
    }

    public function testUsesCohereRerankerWhenKeyIsConfigured(): void
    {
        putenv('AGENTFORGE_COHERE_API_KEY=test-key');

        $this->assertInstanceOf(CohereReranker::class, GuidelineRerankerFactory::createDefault());
    }
}
