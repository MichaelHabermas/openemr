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

    public function testReviewerPathLocalMarkdownLinksResolveFromRoot(): void
    {
        foreach (
            [
                '/README.md' => $this->readRepoFile('/README.md'),
                '/AGENTFORGE-REVIEWER-GUIDE.md' => $this->readRepoFile('/AGENTFORGE-REVIEWER-GUIDE.md'),
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
