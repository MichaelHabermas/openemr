<?php

/**
 * Selects the first mapper that supports the given document type.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\AgentForge\Document\Mapping;

use DomainException;
use OpenEMR\AgentForge\Document\DocumentType;
use OpenEMR\AgentForge\Document\Schema\ClinicalWorkbookExtraction;
use OpenEMR\AgentForge\Document\Schema\FaxPacketExtraction;
use OpenEMR\AgentForge\Document\Schema\Hl7v2MessageExtraction;
use OpenEMR\AgentForge\Document\Schema\IntakeFormExtraction;
use OpenEMR\AgentForge\Document\Schema\LabPdfExtraction;
use OpenEMR\AgentForge\Document\Schema\ReferralDocxExtraction;

final readonly class DocumentFactMapperRegistry
{
    /** @var list<DocumentFactMapper> */
    private array $mappers;

    public function __construct(DocumentFactMapper ...$mappers)
    {
        $this->mappers = array_values($mappers);
    }

    public function mapperFor(DocumentType $documentType): DocumentFactMapper
    {
        foreach ($this->mappers as $mapper) {
            if ($mapper->supports($documentType)) {
                return $mapper;
            }
        }

        throw new DomainException(sprintf('No fact mapper registered for document type "%s".', $documentType->value));
    }

    /**
     * @return list<DocumentFactDraft>
     */
    public function map(
        DocumentType $documentType,
        LabPdfExtraction | IntakeFormExtraction | ReferralDocxExtraction | ClinicalWorkbookExtraction | FaxPacketExtraction | Hl7v2MessageExtraction $extraction,
    ): array {
        return $this->mapperFor($documentType)->map($extraction);
    }
}
