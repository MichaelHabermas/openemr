<?php

/**
 * Defers extraction provider construction until extraction is actually requested.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\AgentForge\Document\Extraction;

use Closure;
use OpenEMR\AgentForge\Deadline;
use OpenEMR\AgentForge\Document\DocumentId;
use OpenEMR\AgentForge\Document\DocumentType;
use OpenEMR\AgentForge\Document\Worker\DocumentLoadResult;

final class LazyExtractionProvider implements DocumentExtractionProvider
{
    private ?DocumentExtractionProvider $provider = null;
    /** @var Closure(): DocumentExtractionProvider */
    private readonly Closure $factory;

    /** @param callable(): DocumentExtractionProvider $factory */
    public function __construct(callable $factory)
    {
        $this->factory = Closure::fromCallable($factory);
    }

    public function extract(
        DocumentId $documentId,
        DocumentLoadResult $document,
        DocumentType $documentType,
        Deadline $deadline,
    ): ExtractionProviderResponse {
        return $this->provider()->extract($documentId, $document, $documentType, $deadline);
    }

    private function provider(): DocumentExtractionProvider
    {
        if ($this->provider === null) {
            $factory = $this->factory;
            $this->provider = $factory();
        }

        return $this->provider;
    }
}
