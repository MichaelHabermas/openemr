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
}
