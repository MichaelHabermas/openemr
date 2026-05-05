<?php

/**
 * SQL-backed document type mapping repository.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\AgentForge\Document;

use DateTimeImmutable;
use DomainException;
use InvalidArgumentException;
use OpenEMR\AgentForge\DatabaseExecutor;
use OpenEMR\AgentForge\DefaultDatabaseExecutor;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

final readonly class SqlDocumentTypeMappingRepository implements DocumentTypeMappingRepository
{
    private DatabaseExecutor $executor;
    private LoggerInterface $logger;

    public function __construct(?DatabaseExecutor $executor = null, ?LoggerInterface $logger = null)
    {
        $this->executor = $executor ?? new DefaultDatabaseExecutor();
        $this->logger = $logger ?? new NullLogger();
    }

    public function findActiveByCategoryId(CategoryId $categoryId): ?DocumentTypeMapping
    {
        $records = $this->executor->fetchRecords(
            'SELECT id, category_id, doc_type, active, created_at '
            . 'FROM clinical_document_type_mappings '
            . 'WHERE category_id = ? AND active = 1 '
            . 'ORDER BY id ASC LIMIT 1',
            [$categoryId->value],
        );

        if ($records === []) {
            return null;
        }

        try {
            return $this->hydrate($records[0]);
        } catch (DomainException | InvalidArgumentException $e) {
            $this->logger->warning('clinical_document.mapping.invalid', [
                'category_id' => $categoryId->value,
                'error_code' => $e::class,
            ]);

            return null;
        }
    }

    /** @param array<string, mixed> $record */
    private function hydrate(array $record): DocumentTypeMapping
    {
        return new DocumentTypeMapping(
            id: isset($record['id']) ? $this->intValue($record['id'], 'id') : null,
            categoryId: new CategoryId($this->intValue($record['category_id'] ?? null, 'category_id')),
            docType: DocumentType::fromStringOrThrow($this->stringValue($record['doc_type'] ?? null, 'doc_type')),
            active: (bool) $record['active'],
            createdAt: new DateTimeImmutable($this->stringValue($record['created_at'] ?? null, 'created_at')),
        );
    }

    private function intValue(mixed $value, string $field): int
    {
        if (!is_scalar($value)) {
            throw new InvalidArgumentException("Expected scalar {$field}.");
        }

        return (int) $value;
    }

    private function stringValue(mixed $value, string $field): string
    {
        if (!is_scalar($value)) {
            throw new InvalidArgumentException("Expected scalar {$field}.");
        }

        return (string) $value;
    }
}
