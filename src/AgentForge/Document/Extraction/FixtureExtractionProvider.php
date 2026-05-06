<?php

/**
 * Deterministic fixture extraction provider keyed by document SHA-256.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\AgentForge\Document\Extraction;

use DomainException;
use JsonException;
use OpenEMR\AgentForge\Deadline;
use OpenEMR\AgentForge\Document\DocumentId;
use OpenEMR\AgentForge\Document\DocumentType;
use OpenEMR\AgentForge\Document\ExtractionErrorCode;
use OpenEMR\AgentForge\Document\Schema\ExtractionSchemaException;
use OpenEMR\AgentForge\Document\Worker\DocumentLoadResult;
use OpenEMR\AgentForge\ResponseGeneration\DraftUsage;
use OpenEMR\AgentForge\StringKeyedArray;

final class FixtureExtractionProvider implements DocumentExtractionProvider
{
    /** @var array<string, array<string, mixed>>|null */
    private ?array $manifest = null;

    public function __construct(private readonly ?string $manifestPath = null)
    {
    }

    public function extract(
        DocumentId $documentId,
        DocumentLoadResult $document,
        DocumentType $documentType,
        Deadline $deadline,
    ): ExtractionProviderResponse {
        if ($deadline->exceeded()) {
            throw new ExtractionProviderException('Deadline exceeded before fixture extraction.');
        }

        $sha256 = hash('sha256', $document->bytes);
        $entry = $this->manifest()[$sha256] ?? null;
        if (!is_array($entry)) {
            throw new ExtractionProviderException(
                sprintf('No fixture extraction found for document sha256 %s.', $sha256),
                ExtractionErrorCode::MissingFile,
            );
        }

        $declaredType = $entry['doc_type'] ?? $entry['document_type'] ?? null;
        if (is_string($declaredType) && $declaredType !== $documentType->value) {
            throw new ExtractionProviderException(sprintf(
                'Fixture extraction doc_type "%s" does not match requested "%s".',
                $declaredType,
                $documentType->value,
            ), ExtractionErrorCode::SchemaValidationFailure);
        }

        try {
            return ExtractionProviderResponse::fromStrictJson(
                $documentType,
                json_encode($entry, JSON_THROW_ON_ERROR),
                DraftUsage::fixture(),
                'fixture',
                $this->listOfStrings($entry['warnings'] ?? []),
            );
        } catch (JsonException | ExtractionSchemaException $exception) {
            throw new ExtractionProviderException(
                'Fixture extraction failed strict schema validation.',
                ExtractionErrorCode::SchemaValidationFailure,
                $exception,
            );
        }
    }

    /** @return array<string, array<string, mixed>> */
    private function manifest(): array
    {
        if ($this->manifest !== null) {
            return $this->manifest;
        }

        if ($this->manifestPath === null || trim($this->manifestPath) === '') {
            $this->manifest = [];
            return $this->manifest;
        }
        if (!is_file($this->manifestPath) || !is_readable($this->manifestPath)) {
            throw new ExtractionProviderException(
                'Fixture extraction manifest is not readable.',
                ExtractionErrorCode::MissingFile,
            );
        }

        try {
            $decoded = json_decode((string) file_get_contents($this->manifestPath), true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new ExtractionProviderException(
                'Fixture extraction manifest is invalid JSON.',
                ExtractionErrorCode::SchemaValidationFailure,
                $exception,
            );
        }

        if (!is_array($decoded)) {
            throw new ExtractionProviderException('Fixture extraction manifest must be a JSON object.');
        }

        if (isset($decoded['fixtures_by_sha256']) && is_array($decoded['fixtures_by_sha256'])) {
            $this->manifest = $this->manifestFromFixtureFiles($decoded['fixtures_by_sha256']);
            return $this->manifest;
        }

        $entries = $decoded['documents'] ?? $decoded;
        if (!is_array($entries)) {
            throw new ExtractionProviderException('Fixture extraction manifest documents must be an object.');
        }

        $manifest = [];
        foreach ($entries as $sha256 => $entry) {
            if (!is_string($sha256) || !preg_match('/^[a-f0-9]{64}$/', $sha256) || !is_array($entry)) {
                throw new DomainException('Fixture extraction manifest entries must be keyed by lowercase sha256.');
            }
            $manifest[$sha256] = StringKeyedArray::filter($entry);
        }

        $this->manifest = $manifest;
        return $this->manifest;
    }

    /**
     * @param array<mixed> $fixtures
     * @return array<string, array<string, mixed>>
     */
    private function manifestFromFixtureFiles(array $fixtures): array
    {
        $baseDir = dirname((string) $this->manifestPath);
        $manifest = [];
        foreach ($fixtures as $sha256 => $fixtureFile) {
            if (!is_string($sha256) || !preg_match('/^[a-f0-9]{64}$/', $sha256) || !is_string($fixtureFile)) {
                throw new DomainException('Fixture extraction manifest entries must map lowercase sha256 to fixture files.');
            }

            $path = $baseDir . '/' . basename($fixtureFile);
            if (!is_file($path) || !is_readable($path)) {
                throw new ExtractionProviderException(
                    'Fixture extraction file is not readable.',
                    ExtractionErrorCode::MissingFile,
                );
            }

            try {
                $decoded = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
            } catch (JsonException $exception) {
                throw new ExtractionProviderException(
                    'Fixture extraction file is invalid JSON.',
                    ExtractionErrorCode::SchemaValidationFailure,
                    $exception,
                );
            }

            if (!is_array($decoded)) {
                throw new ExtractionProviderException('Fixture extraction file must be a JSON object.');
            }

            $entry = StringKeyedArray::filter($decoded);
            $entry['doc_type'] = $entry['doc_type'] ?? $entry['document_type'] ?? null;
            $manifest[$sha256] = $entry;
        }

        return $manifest;
    }

    /**
     * @param mixed $value
     * @return list<string>
     */
    private function listOfStrings(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        $items = [];
        foreach ($value as $item) {
            if (is_string($item) && trim($item) !== '') {
                $items[] = $item;
            }
        }

        return $items;
    }
}
