<?php

/**
 * Isolated regression tests for the AgentForge reviewer entry point.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Tests\Isolated\AgentForge;

use PHPUnit\Framework\TestCase;

final class ReviewerGuideDocumentTest extends TestCase
{
    public function testReadmeLinksToExistingReviewerGuide(): void
    {
        $readme = $this->readRepoFile('/README.md');
        $guidePath = $this->repoPath('/AGENTFORGE-REVIEWER-GUIDE.md');

        $this->assertFileExists($guidePath);
        $this->assertStringContainsString('AgentForge Reviewer Entry Point', $readme);
        $this->assertStringContainsString('[AGENTFORGE-REVIEWER-GUIDE.md](AGENTFORGE-REVIEWER-GUIDE.md)', $readme);
    }

    public function testReviewerGuideContainsEpic15RequiredSectionsAndCommands(): void
    {
        $guide = $this->reviewerGuide();

        foreach (
            [
                '## Documented Deployed URL',
                '## Fake Patient And Demo Credentials Policy',
                '## Demo Path',
                '## Seed And Verify Commands',
                '## Deterministic Eval Command',
                '## Artifact Map',
                '## Implemented Proof Summary',
                '## Known Blockers And Production-Readiness Caveats',
                '## Reviewer Navigation Checklist',
            ] as $heading
        ) {
            $this->assertStringContainsString($heading, $guide);
        }

        foreach (
            [
                'https://openemr.titleredacted.cc/',
                'agent-forge/scripts/health-check.sh',
                '900001',
                'AF-DEMO-900001',
                'assigned out-of-band',
                'agent-forge/scripts/seed-demo-data.sh',
                'agent-forge/scripts/verify-demo-data.sh',
                'php agent-forge/scripts/run-evals.php',
                'agent-forge/docs/operations/COST-ANALYSIS.md',
                'agent-forge/docs/evaluation/EVALUATION-TIERS.md',
                'Production-Readiness is not claimed',
            ] as $requiredText
        ) {
            $this->assertStringContainsString($requiredText, $guide);
        }
    }

    public function testReviewerGuideLinksRequiredArtifacts(): void
    {
        $guide = $this->reviewerGuide();

        foreach (
            [
                'AUDIT.md',
                'USERS.md',
                'ARCHITECTURE.md',
                'agent-forge/docs/week1/SPECS.txt',
                'agent-forge/docs/week1/PRD.md',
                'agent-forge/docs/week1/PLAN.md',
                'agent-forge/docs/operations/KNOWN-FACTS-AND-NEEDS.md',
                'agent-forge/docs/operations/COST-ANALYSIS.md',
                'agent-forge/docs/evaluation/EVALUATION-TIERS.md',
                'agent-forge/docs/epics/EPIC_REVIEWER_ENTRY_POINT_SUBMISSION_MAP.md',
            ] as $artifactPath
        ) {
            $this->assertStringContainsString('(' . $artifactPath . ')', $guide);
        }
    }

    public function testReviewerPathLocalMarkdownLinksResolveFromRoot(): void
    {
        foreach (
            [
                '/README.md' => $this->readRepoFile('/README.md'),
                '/AGENTFORGE-REVIEWER-GUIDE.md' => $this->reviewerGuide(),
            ] as $documentPath => $document
        ) {
            foreach ($this->localMarkdownLinks($document) as $linkTarget) {
                $targetWithoutFragment = explode('#', $linkTarget, 2)[0];

                if ($targetWithoutFragment === '') {
                    continue;
                }

                $this->assertFileExists(
                    $this->repoPath('/' . $targetWithoutFragment),
                    $documentPath . ' links to missing local artifact: ' . $linkTarget
                );
            }
        }
    }

    public function testReviewerGuideDoesNotExposeSecretsOrClaimProductionReadiness(): void
    {
        $guide = $this->reviewerGuide();

        foreach (
            [
                'demo password',
                'admin password',
                'OPENAI_API_KEY=',
                'ANTHROPIC_API_KEY=',
                'production ready',
                'production-ready',
                'Production ready',
                'Production-ready',
            ] as $forbiddenClaim
        ) {
            $this->assertStringNotContainsString($forbiddenClaim, $guide);
        }

        $this->assertStringContainsString('Demo credentials are not committed to the repository', $guide);
        $this->assertStringContainsString('Production-Readiness is not claimed', $guide);
    }

    private function reviewerGuide(): string
    {
        return $this->readRepoFile('/AGENTFORGE-REVIEWER-GUIDE.md');
    }

    private function readRepoFile(string $path): string
    {
        $document = file_get_contents($this->repoPath($path));

        $this->assertIsString($document);

        return $document;
    }

    private function repoPath(string $path): string
    {
        return dirname(__DIR__, 4) . $path;
    }

    /**
     * @return list<string>
     */
    private function localMarkdownLinks(string $document): array
    {
        preg_match_all('/\[[^\]]+\]\(([^)]+)\)/', $document, $matches);

        return array_values(array_filter(
            $matches[1],
            static fn (string $target): bool => !preg_match('/^(?:https?:|mailto:|#)/', $target)
        ));
    }
}
