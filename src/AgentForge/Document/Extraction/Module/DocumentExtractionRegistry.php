<?php

/**
 * Registry for document type-to-pipeline mappings.
 *
 * Centralizes the configuration of which strategies handle which document types,
 * enabling Open/Closed Principle: new types register without modifying core logic.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\AgentForge\Document\Extraction\Module;

use DomainException;
use OpenEMR\AgentForge\Document\DocumentType;
use OpenEMR\AgentForge\Document\Extraction\Module\Strategy\ExtractionStrategy;

final class DocumentExtractionRegistry
{
    /** @var array<string, ExtractionPipeline> */
    private array $pipelines = [];

    /**
     * Register a document type with its extraction strategy.
     *
     * @param DocumentType $type The document type to register
     * @param ExtractionStrategy $strategy The strategy for this type
     */
    public function register(DocumentType $type, ExtractionStrategy $strategy): void
    {
        $this->pipelines[$type->value] = new ExtractionPipeline(
            type: $type,
            strategy: $strategy,
            mapper: $strategy->getMapper(),
            schemaClass: $strategy->getSchemaClass(),
        );
    }

    /**
     * Get the extraction pipeline for a document type.
     *
     * @param DocumentType $type The document type to look up
     * @return ExtractionPipeline The configured pipeline
     * @throws DomainException If the type is not registered
     */
    public function getPipeline(DocumentType $type): ExtractionPipeline
    {
        if (!isset($this->pipelines[$type->value])) {
            throw new DomainException(
                sprintf('No extraction pipeline registered for document type: %s', $type->value)
            );
        }

        return $this->pipelines[$type->value];
    }

    /**
     * Check if a document type is registered.
     */
    public function isRegistered(DocumentType $type): bool
    {
        return isset($this->pipelines[$type->value]);
    }

    /**
     * Get all registered document types.
     *
     * @return list<DocumentType>
     */
    public function registeredTypes(): array
    {
        $types = [];
        foreach ($this->pipelines as $pipeline) {
            $types[] = $pipeline->type;
        }

        return $types;
    }
}
