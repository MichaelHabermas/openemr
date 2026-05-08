<?php

/**
 * Validates the clinical-document fixture corpus from source bytes to strict extraction sidecars.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\AgentForge\Eval\ClinicalDocument\Fixture;

use JsonException;
use OpenEMR\AgentForge\Document\DocumentType;
use OpenEMR\AgentForge\Document\Extraction\ExtractionProviderResponse;
use OpenEMR\AgentForge\Document\Schema\ExtractionSchemaException;
use OpenEMR\AgentForge\ResponseGeneration\DraftUsage;
use OpenEMR\AgentForge\StringKeyedArray;

final readonly class GoldenFixtureCorpusValidator
{
    /**
     * @return list<string>
     */
    public function validate(string $repoDir, string $sourceManifestPath, string $extractionManifestPath): array
    {
        $violations = [];
        $sourceManifest = $this->loadJson($sourceManifestPath);
        $extractionManifest = $this->loadJson($extractionManifestPath);
        $sourceRoot = $sourceManifest['root'] ?? null;
        $fixtures = $sourceManifest['fixtures'] ?? null;
        $fixturesBySha = $extractionManifest['fixtures_by_sha256'] ?? null;
        if (!is_string($sourceRoot) || !is_array($fixtures) || !is_array($fixturesBySha)) {
            return ['Fixture manifests must include root, fixtures, and fixtures_by_sha256.'];
        }

        $sourceFixturesBySha = [];
        foreach ($fixtures as $index => $fixture) {
            if (!is_array($fixture)) {
                $violations[] = sprintf('Fixture manifest entry %d must be an object.', $index);
                continue;
            }

            $entry = StringKeyedArray::filter($fixture);
            $path = $entry['path'] ?? null;
            $sha256 = $entry['sha256'] ?? null;
            $role = $entry['role'] ?? null;
            $docType = $entry['doc_type'] ?? null;
            if (!is_string($path) || !is_string($sha256) || !is_string($role)) {
                $violations[] = sprintf('Fixture manifest entry %d is missing path, sha256, or role.', $index);
                continue;
            }
            if (!in_array($role, ['ingestion_input', 'preview_only'], true)) {
                $violations[] = sprintf('Fixture manifest entry %s has unsupported role %s.', $path, $role);
                continue;
            }
            if (isset($sourceFixturesBySha[$sha256])) {
                $violations[] = sprintf('Fixture manifest has duplicate sha256 %s for %s.', $sha256, $path);
                continue;
            }
            $sourceFixturesBySha[$sha256] = $entry;

            $absolutePath = rtrim($repoDir, '/') . '/' . trim($sourceRoot, '/') . '/' . ltrim($path, '/');
            if (!is_file($absolutePath)) {
                $violations[] = sprintf('Source fixture is missing: %s.', $path);
                continue;
            }
            $actualSha = hash_file('sha256', $absolutePath);
            if ($actualSha !== $sha256) {
                $violations[] = sprintf('Source fixture sha256 mismatch for %s.', $path);
                continue;
            }

            if ($role === 'preview_only') {
                if ($docType !== null || ($entry['golden_extraction'] ?? null) !== null) {
                    $violations[] = sprintf('Preview-only fixture must not declare doc_type or golden extraction: %s.', $path);
                }
                if (array_key_exists($sha256, $fixturesBySha)) {
                    $violations[] = sprintf('Preview-only fixture must not be mapped in extraction manifest: %s.', $path);
                }
                continue;
            }

            if (!is_string($docType) || DocumentType::tryFrom($docType) === null) {
                $violations[] = sprintf('Ingestion fixture has unsupported doc_type for %s.', $path);
                continue;
            }
            $sidecarName = $fixturesBySha[$sha256] ?? null;
            $linkedSidecar = $entry['golden_extraction'] ?? null;
            if ($linkedSidecar !== null && !is_string($linkedSidecar)) {
                $violations[] = sprintf('golden_extraction must be a string or null for %s.', $path);
                continue;
            }
            if ($linkedSidecar === null) {
                continue;
            }
            if (!is_string($sidecarName) || basename($linkedSidecar) !== basename($sidecarName)) {
                $violations[] = sprintf('Extraction manifest does not map %s to %s.', $path, basename($linkedSidecar));
                continue;
            }

            $sidecarPath = dirname($extractionManifestPath) . '/' . basename($sidecarName);
            if (!is_file($sidecarPath)) {
                $violations[] = sprintf('Extraction sidecar is missing: %s.', basename($sidecarName));
                continue;
            }

            $sidecarJson = file_get_contents($sidecarPath);
            if (!is_string($sidecarJson)) {
                $violations[] = sprintf('Extraction sidecar is unreadable: %s.', basename($sidecarName));
                continue;
            }
            try {
                ExtractionProviderResponse::fromStrictJson(
                    DocumentType::fromStringOrThrow($docType),
                    $sidecarJson,
                    DraftUsage::fixture(),
                    'fixture',
                );
            } catch (JsonException | ExtractionSchemaException | \DomainException $exception) {
                $violations[] = sprintf('Extraction sidecar failed strict validation for %s: %s', $path, $exception->getMessage());
            }
        }

        foreach ($fixturesBySha as $sha256 => $sidecarName) {
            if (!is_string($sha256) || !preg_match('/^[a-f0-9]{64}$/', $sha256)) {
                $violations[] = 'Extraction manifest contains an invalid sha256 key.';
                continue;
            }
            if (!is_string($sidecarName)) {
                $violations[] = sprintf('Extraction manifest value for %s must be a sidecar filename.', $sha256);
                continue;
            }
            $sourceEntry = $sourceFixturesBySha[$sha256] ?? null;
            if (!is_array($sourceEntry)) {
                $violations[] = sprintf('Extraction manifest maps unknown source sha256 %s.', $sha256);
                continue;
            }
            $path = $sourceEntry['path'] ?? $sha256;
            $role = $sourceEntry['role'] ?? null;
            if ($role === 'preview_only') {
                $violations[] = sprintf('Extraction manifest must not map preview-only fixture %s.', is_string($path) ? $path : $sha256);
                continue;
            }
            if ($role !== 'ingestion_input') {
                $violations[] = sprintf('Extraction manifest maps fixture with unsupported role for %s.', is_string($path) ? $path : $sha256);
                continue;
            }
            $linkedSidecar = $sourceEntry['golden_extraction'] ?? null;
            if (!is_string($linkedSidecar) || basename($linkedSidecar) !== basename($sidecarName)) {
                $violations[] = sprintf('Extraction manifest maps %s without a matching source manifest golden_extraction.', is_string($path) ? $path : $sha256);
            }
        }

        return $violations;
    }

    /** @return array<string, mixed> */
    private function loadJson(string $path): array
    {
        try {
            $decoded = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            return ['__error' => $exception->getMessage()];
        }

        return is_array($decoded) ? StringKeyedArray::filter($decoded) : [];
    }
}
