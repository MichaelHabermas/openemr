<?php

/**
 * Isolated documentation contract tests for the Week 2 reviewer path.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Tests\Isolated\AgentForge;

use PHPUnit\Framework\TestCase;

final class AgentForgeWeek2DocumentationContractTest extends TestCase
{
    use AgentForgeDocsTestTrait;

    public function testRootReadmeSeparatesWeekOneAndWeekTwoReviewerPaths(): void
    {
        $this->assertDocumentContainsAll('/README.md', [
            'AgentForge Reviewer Entry Point',
            '[AGENTFORGE-REVIEWER-GUIDE.md](AGENTFORGE-REVIEWER-GUIDE.md)',
            '[agent-forge/docs/week2/README.md](agent-forge/docs/week2/README.md)',
            'Week 1 baseline',
            'Week 2 multimodal flow',
            'intake-extractor',
            'evidence-retriever',
        ]);
    }

    public function testReviewerGuideLinksWeekTwoProofSurface(): void
    {
        $this->assertDocumentContainsAll('/AGENTFORGE-REVIEWER-GUIDE.md', [
            '[agent-forge/docs/week2/W2_ACCEPTANCE_MATRIX.md](agent-forge/docs/week2/W2_ACCEPTANCE_MATRIX.md)',
            '[agent-forge/docs/operations/CLINICAL-DOCUMENT-COST-LATENCY.md](agent-forge/docs/operations/CLINICAL-DOCUMENT-COST-LATENCY.md)',
            '[agent-forge/eval-results/README.md](agent-forge/eval-results/README.md)',
            '[.github/workflows/agentforge-deployed-smoke.yml](.github/workflows/agentforge-deployed-smoke.yml)',
            'clinical-document-20260508-190800',
            'BHS-2847163',
            'assigned-vm-ssh-host',
            'eval-results-20260508-161500.json',
        ]);
    }

    public function testWeekTwoDemoPathIsDocumentWorkflowNotOnlyAChartQuestion(): void
    {
        $this->assertDocumentContainsAll('/AGENTFORGE-REVIEWER-GUIDE.md', [
            'Week 2 Clinical Document Demo Path',
            'Upload or attach a lab PDF',
            'Upload or attach an intake form',
            'agentforge-worker',
            'intake-extractor',
            'What changed, what should I pay attention to, and what evidence supports it?',
            'source review',
            'Guideline Evidence',
        ]);
    }

    public function testWeekTwoHubDocumentsCanonicalGatesAndMatrix(): void
    {
        $this->assertDocumentContainsAll('/agent-forge/docs/week2/README.md', [
            'agent-forge/scripts/check-agentforge.sh',
            'agent-forge/scripts/check-clinical-document.sh',
            '[W2_ACCEPTANCE_MATRIX.md](W2_ACCEPTANCE_MATRIX.md)',
            'clinical-document-20260508-190800',
            'clinical-document-deployed-smoke-20260508-001525.json',
        ]);
    }
}
