<?php

/**
 * Parses and indexes the checked-in clinical guideline corpus.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\AgentForge\Guidelines;

use RuntimeException;

final readonly class GuidelineCorpusIndexer
{
    public function __construct(
        private GuidelineChunkRepository $repository,
        private GuidelineEmbeddingProvider $embeddingProvider,
        private string $corpusDir,
    ) {
    }

    public function index(): int
    {
        $version = $this->corpusVersion();
        $chunks = $this->loadChunks($version);
        $this->repository->replaceCorpus($version, $chunks, $this->embeddingProvider);

        return count($chunks);
    }

    public function corpusVersion(): string
    {
        $path = rtrim($this->corpusDir, '/') . '/corpus-version.txt';
        $version = is_file($path) ? trim((string) file_get_contents($path)) : '';
        if ($version === '') {
            throw new RuntimeException('Guideline corpus version file is missing or empty.');
        }

        return $version;
    }

    /**
     * @return list<GuidelineChunk>
     */
    public function loadChunks(?string $corpusVersion = null): array
    {
        $version = $corpusVersion ?? $this->corpusVersion();
        $files = glob(rtrim($this->corpusDir, '/') . '/*.md');
        if ($files === false || $files === []) {
            throw new RuntimeException('No guideline corpus markdown files were found.');
        }
        sort($files);

        $chunks = [];
        foreach ($files as $file) {
            foreach ($this->parseMarkdownFile($file, $version) as $chunk) {
                $chunks[] = $chunk;
            }
        }

        return $chunks;
    }

    /**
     * @return list<GuidelineChunk>
     */
    private function parseMarkdownFile(string $file, string $corpusVersion): array
    {
        $contents = file_get_contents($file);
        if (!is_string($contents)) {
            throw new RuntimeException(sprintf('Could not read guideline corpus file: %s', $file));
        }

        [$metadata, $body] = $this->splitMetadata($contents);
        $sourceTitle = $metadata['source_title'] ?? basename($file, '.md');
        $sourceUrlOrFile = $metadata['source_url_or_file'] ?? $file;
        preg_match_all('/^##\s+(.+?)\s*$(.*?)(?=^##\s+|\z)/ms', $body, $matches, PREG_SET_ORDER);

        $chunks = [];
        $index = 1;
        foreach ($matches as $match) {
            $section = trim($match[1]);
            $text = trim(preg_replace('/\s+/', ' ', $match[2]) ?? '');
            if ($section === '' || $text === '') {
                continue;
            }
            $chunkId = sprintf('%s-%02d-%s', $this->slug(basename($file, '.md')), $index, $this->slug($section));
            $chunks[] = new GuidelineChunk(
                $chunkId,
                $corpusVersion,
                $sourceTitle,
                $sourceUrlOrFile,
                $section,
                $text,
                [],
            );
            $index++;
        }

        return $chunks;
    }

    /**
     * @return array{array<string, string>, string}
     */
    private function splitMetadata(string $contents): array
    {
        if (!str_starts_with($contents, "---\n")) {
            return [[], $contents];
        }

        $end = strpos($contents, "\n---\n", 4);
        if ($end === false) {
            return [[], $contents];
        }

        $metadata = [];
        $header = substr($contents, 4, $end - 4);
        foreach (explode("\n", $header) as $line) {
            $parts = explode(':', $line, 2);
            if (count($parts) === 2) {
                $metadata[trim($parts[0])] = trim($parts[1]);
            }
        }

        return [$metadata, substr($contents, $end + 5)];
    }

    private function slug(string $text): string
    {
        $slug = strtolower((string) preg_replace('/[^a-z0-9]+/i', '-', $text));
        $slug = trim($slug, '-');

        return $slug === '' ? 'chunk' : $slug;
    }
}
