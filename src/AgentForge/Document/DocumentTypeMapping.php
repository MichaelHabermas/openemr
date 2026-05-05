<?php

/**
 * Active OpenEMR category to AgentForge document type mapping.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\AgentForge\Document;

use DateTimeImmutable;

final readonly class DocumentTypeMapping
{
    public function __construct(
        public ?int $id,
        public CategoryId $categoryId,
        public DocumentType $docType,
        public bool $active,
        public DateTimeImmutable $createdAt,
    ) {
    }
}
