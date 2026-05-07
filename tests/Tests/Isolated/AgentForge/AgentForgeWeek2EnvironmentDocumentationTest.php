<?php

/**
 * Isolated tests for Week 2 environment-variable documentation.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Tests\Isolated\AgentForge;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class AgentForgeWeek2EnvironmentDocumentationTest extends TestCase
{
    use AgentForgeDocsTestTrait;

    #[DataProvider('weekTwoEnvironmentVariables')]
    public function testWeekTwoEnvironmentVariableIsDiscoverable(string $variable): void
    {
        $combinedDocs = implode("\n", [
            $this->readRepoFile('/agent-forge/.env.sample'),
            $this->readRepoFile('/AGENTFORGE-REVIEWER-GUIDE.md'),
            $this->readRepoFile('/agent-forge/docs/week2/README.md'),
        ]);

        $this->assertStringContainsString($variable, $combinedDocs);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function weekTwoEnvironmentVariables(): array
    {
        return [
            'draft provider' => ['AGENTFORGE_DRAFT_PROVIDER'],
            'openai key' => ['AGENTFORGE_OPENAI_API_KEY'],
            'openai model' => ['AGENTFORGE_OPENAI_MODEL'],
            'vlm provider' => ['AGENTFORGE_VLM_PROVIDER'],
            'vlm model' => ['AGENTFORGE_VLM_MODEL'],
            'cohere key' => ['AGENTFORGE_COHERE_API_KEY'],
            'embedding model' => ['AGENTFORGE_EMBEDDING_MODEL'],
            'worker sleep' => ['AGENTFORGE_WORKER_IDLE_SLEEP_SECONDS'],
            'clinical enabled' => ['AGENTFORGE_CLINICAL_DOCUMENT_ENABLED'],
            'smoke user' => ['AGENTFORGE_SMOKE_USER'],
            'smoke password' => ['AGENTFORGE_SMOKE_PASSWORD'],
            'deployed url' => ['AGENTFORGE_DEPLOYED_URL'],
        ];
    }
}
