<?php

/**
 * Strategy interface for extracting structured facts from a specific document type.
 *
 * Each document type (lab_pdf, intake_form, etc.) implements this interface
 * to encapsulate its specific extraction, schema validation, and fact mapping logic.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\AgentForge\Document\Extraction\Module\Strategy;

use OpenEMR\AgentForge\Deadline;
use OpenEMR\AgentForge\Document\DocumentFactMapper;
use OpenEMR\AgentForge\Document\DocumentType;
use OpenEMR\AgentForge\Document\Worker\DocumentLoadResult;
use OpenEMR\AgentForge\Document\Extraction\ExtractionProviderResponse;

interface ExtractionStrategy
{
    /**
     * Check if this strategy supports the given document type.
     */
    public function supports(DocumentType $type): bool;

    /**
     * Extract structured facts from a loaded document.
     *
     * @param DocumentLoadResult $document The loaded document bytes and metadata
     * @param Deadline $deadline Time constraint for extraction
     * @return ExtractionProviderResponse Structured extraction result with schema validation
     */
    public function extract(DocumentLoadResult $document, Deadline $deadline): ExtractionProviderResponse;

    /**
     * Get the fact mapper for converting extraction to domain facts.
     */
    public function getMapper(): DocumentFactMapper;

    /**
     * Get the schema class/type that this strategy produces.
     *
     * @return class-string<object> The schema class name (e.g., LabPdfExtraction::class)
     */
    public function getSchemaClass(): string;
}
