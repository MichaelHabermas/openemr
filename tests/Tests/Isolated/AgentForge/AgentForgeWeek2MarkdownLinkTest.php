<?php

/**
 * Isolated link-resolution tests for Week 2 reviewer documentation.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Tests\Isolated\AgentForge;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class AgentForgeWeek2MarkdownLinkTest extends TestCase
{
    use AgentForgeDocsTestTrait;

    #[DataProvider('weekTwoDocuments')]
    public function testLocalMarkdownLinksResolve(string $documentPath): void
    {
        $this->assertLocalMarkdownLinksResolve($documentPath);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function weekTwoDocuments(): array
    {
        return [
            'root readme' => ['/README.md'],
            'reviewer guide' => ['/AGENTFORGE-REVIEWER-GUIDE.md'],
            'docs hub' => ['/agent-forge/docs/README.md'],
            'week2 hub' => ['/agent-forge/docs/week2/README.md'],
            'week2 plan' => ['/agent-forge/docs/week2/PLAN-W2.md'],
            'week2 specs' => ['/agent-forge/docs/week2/SPECS-W2.md'],
            'week2 acceptance matrix' => ['/agent-forge/docs/week2/W2_ACCEPTANCE_MATRIX.md'],
            'week2 architecture' => ['/W2_ARCHITECTURE.md'],
            'cost latency report' => ['/agent-forge/docs/operations/CLINICAL-DOCUMENT-COST-LATENCY.md'],
            'eval results readme' => ['/agent-forge/eval-results/README.md'],
        ];
    }
}
