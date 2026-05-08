<?php

/**
 * Integrity checks for clinical-document golden extraction manifest and sidecars.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/open-emr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Tests\Isolated\AgentForge\Eval\ClinicalDocument;

use JsonException;
use OpenEMR\AgentForge\Eval\ClinicalDocument\Fixture\GoldenFixtureCorpusValidator;
use PHPUnit\Framework\TestCase;

final class ClinicalDocumentGoldenManifestIntegrityTest extends TestCase
{
    public function testExtractionManifestSidecarsExistAndAreValidJson(): void
    {
        $repo = dirname(__DIR__, 6);
        $manifestPath = $repo . '/agent-forge/fixtures/clinical-document-golden/extraction/manifest.json';
        $this->assertFileExists($manifestPath);

        $raw = file_get_contents($manifestPath);
        $this->assertIsString($raw);
        try {
            $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            $this->fail('Manifest JSON invalid: ' . $e->getMessage());
        }

        $this->assertIsArray($decoded);
        $map = $decoded['fixtures_by_sha256'] ?? null;
        $this->assertIsArray($map);
        $this->assertNotSame([], $map);

        $baseDir = dirname($manifestPath);
        foreach ($map as $sha256 => $relativeFile) {
            $this->assertIsString($sha256);
            $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $sha256);
            $this->assertIsString($relativeFile);
            $path = $baseDir . '/' . basename($relativeFile);
            $this->assertFileExists($path, $relativeFile);
            $json = file_get_contents($path);
            $this->assertIsString($json);
            $sidecar = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
            $this->assertIsArray($sidecar);
            $this->assertArrayHasKey('doc_type', $sidecar);
        }
    }

    public function testSourceFixtureManifestMatchesSourceBytesAndStrictSidecars(): void
    {
        $repo = dirname(__DIR__, 6);
        $violations = (new GoldenFixtureCorpusValidator())->validate(
            $repo,
            $repo . '/agent-forge/fixtures/clinical-document-golden/source-fixture-manifest.json',
            $repo . '/agent-forge/fixtures/clinical-document-golden/extraction/manifest.json',
        );

        $this->assertSame([], $violations);
    }

    public function testCorpusValidatorRejectsPreviewOnlyExtractionManifestMappings(): void
    {
        $repo = self::tempCorpusRepo('preview-bytes');
        $sha256 = hash_file('sha256', $repo . '/fixtures/preview.png');
        $sourceManifest = [
            'root' => 'fixtures',
            'fixtures' => [[
                'fixture_id' => 'preview',
                'path' => 'preview.png',
                'extension' => 'png',
                'mime_type' => 'image/png',
                'sha256' => $sha256,
                'doc_type' => null,
                'role' => 'preview_only',
                'golden_extraction' => null,
            ]],
        ];
        $extractionManifest = ['fixtures_by_sha256' => [$sha256 => 'preview.json']];

        $violations = self::validateTempCorpus($repo, $sourceManifest, $extractionManifest);

        $this->assertContains('Preview-only fixture must not be mapped in extraction manifest: preview.png.', $violations);
        $this->assertContains('Extraction manifest must not map preview-only fixture preview.png.', $violations);
    }

    public function testCorpusValidatorRejectsUnknownRolesAndUnknownMappedSha(): void
    {
        $repo = self::tempCorpusRepo('doc-bytes');
        $sha256 = hash_file('sha256', $repo . '/fixtures/preview.png');
        $sourceManifest = [
            'root' => 'fixtures',
            'fixtures' => [[
                'fixture_id' => 'typo',
                'path' => 'preview.png',
                'extension' => 'png',
                'mime_type' => 'image/png',
                'sha256' => $sha256,
                'doc_type' => null,
                'role' => 'ingest_input',
                'golden_extraction' => null,
            ]],
        ];
        $unknownSha = str_repeat('a', 64);
        $extractionManifest = ['fixtures_by_sha256' => [$unknownSha => 'stray.json']];

        $violations = self::validateTempCorpus($repo, $sourceManifest, $extractionManifest);

        $this->assertContains('Fixture manifest entry preview.png has unsupported role ingest_input.', $violations);
        $this->assertContains(sprintf('Extraction manifest maps unknown source sha256 %s.', $unknownSha), $violations);
    }

    /**
     * @param array<string, mixed> $sourceManifest
     * @param array<string, mixed> $extractionManifest
     *
     * @return list<string>
     */
    private static function validateTempCorpus(string $repo, array $sourceManifest, array $extractionManifest): array
    {
        $sourceManifestPath = $repo . '/source-fixture-manifest.json';
        $extractionDir = $repo . '/extraction';
        mkdir($extractionDir);
        $extractionManifestPath = $extractionDir . '/manifest.json';
        file_put_contents($sourceManifestPath, json_encode($sourceManifest, JSON_THROW_ON_ERROR));
        file_put_contents($extractionManifestPath, json_encode($extractionManifest, JSON_THROW_ON_ERROR));

        return (new GoldenFixtureCorpusValidator())->validate($repo, $sourceManifestPath, $extractionManifestPath);
    }

    private static function tempCorpusRepo(string $fixtureBytes): string
    {
        $repo = sys_get_temp_dir() . '/agentforge-corpus-' . bin2hex(random_bytes(6));
        mkdir($repo . '/fixtures', 0777, true);
        file_put_contents($repo . '/fixtures/preview.png', $fixtureBytes);

        return $repo;
    }
}
