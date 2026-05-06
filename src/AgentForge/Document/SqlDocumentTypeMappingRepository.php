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
use OpenEMR\AgentForge\Observability\SensitiveLogPolicy;
use OpenEMR\AgentForge\RowHydrator;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

final readonly class SqlDocumentTypeMappingRepository implements DocumentTypeMappingRepository
{
    private LoggerInterface $logger;

    public function __construct(private DatabaseExecutor $executor, ?LoggerInterface $logger = null)
    {
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
            $this->logger->warning(
                'clinical_document.mapping.invalid',
                SensitiveLogPolicy::throwableErrorContext($e, [
                    'category_id' => $categoryId->value,
                ]),
            );

            return null;
        }
    }

    /** @param array<string, mixed> $record */
    private function hydrate(array $record): DocumentTypeMapping
    {
        return new DocumentTypeMapping(
            id: isset($record['id']) ? RowHydrator::intValue($record['id'], 'id') : null,
            categoryId: new CategoryId(RowHydrator::intValue($record['category_id'] ?? null, 'category_id')),
            docType: DocumentType::fromStringOrThrow(RowHydrator::stringValue($record['doc_type'] ?? null, 'doc_type')),
            active: (bool) $record['active'],
            createdAt: new DateTimeImmutable(RowHydrator::stringValue($record['created_at'] ?? null, 'created_at')),
        );
    }
}
