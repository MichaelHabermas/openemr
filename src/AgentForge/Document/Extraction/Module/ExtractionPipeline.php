<?php

/**
 * Immutable value object representing a configured extraction pipeline for a document type.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\AgentForge\Document\Extraction\Module;

use OpenEMR\AgentForge\Document\DocumentFactMapper;
use OpenEMR\AgentForge\Document\DocumentType;
use OpenEMR\AgentForge\Document\Extraction\Module\Strategy\ExtractionStrategy;

final readonly class ExtractionPipeline
{
    /**
     * @param DocumentType $type The document type this pipeline handles
     * @param ExtractionStrategy $strategy The strategy for extraction
     * @param DocumentFactMapper $mapper The mapper for fact conversion
     * @param class-string<object> $schemaClass The expected schema class name
     */
    public function __construct(
        public DocumentType $type,
        public ExtractionStrategy $strategy,
        public DocumentFactMapper $mapper,
        public string $schemaClass,
    ) {
    }
}
