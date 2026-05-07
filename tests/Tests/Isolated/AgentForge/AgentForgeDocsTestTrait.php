<?php

/**
 * Shared helpers for AgentForge documentation contract tests.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Tests\Isolated\AgentForge;

trait AgentForgeDocsTestTrait
{
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
     * @param list<string> $needles
     */
    private function assertDocumentContainsAll(string $path, array $needles): void
    {
        $document = $this->readRepoFile($path);

        foreach ($needles as $needle) {
            $this->assertStringContainsString(
                $needle,
                $document,
                $path . ' must document ' . $needle
            );
        }
    }

    private function assertLocalMarkdownLinksResolve(string $documentPath): void
    {
        $document = $this->readRepoFile($documentPath);
        $baseDir = dirname($documentPath);

        foreach ($this->localMarkdownLinks($document) as $linkTarget) {
            $resolved = $this->resolveRelativePath($baseDir, $linkTarget);

            if ($resolved === null) {
                continue;
            }

            $this->assertFileExists(
                $this->repoPath($resolved),
                $documentPath . ' links to missing path: ' . $linkTarget
            );
        }
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

    /**
     * @return ?non-empty-string Absolute path from repo root (leading slash) or null to skip
     */
    private function resolveRelativePath(string $baseDir, string $linkTarget): ?string
    {
        $path = explode('#', $linkTarget, 2)[0];

        if ($path === '') {
            return null;
        }

        $normalized = $path;

        if (!str_starts_with($path, '/')) {
            $normalized = $baseDir . '/' . $path;
        }

        $parts = [];
        foreach (explode('/', $normalized) as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }

            if ($segment === '..') {
                array_pop($parts);

                continue;
            }

            $parts[] = $segment;
        }

        if ($parts === []) {
            return null;
        }

        return '/' . implode('/', $parts);
    }
}
