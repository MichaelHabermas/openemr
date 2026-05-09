<?php

/**
 * Normalized pre-persistence shape for an extracted clinical document fact.
 *
 * Mappers produce drafts; the classifier determines certainty; the fingerprinter
 * produces stable hashes; and the promotion repository persists the final fact.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\AgentForge\Document\Mapping;

use DomainException;
use OpenEMR\AgentForge\Document\Schema\Certainty;
use OpenEMR\AgentForge\Document\Schema\DocumentCitation;

final readonly class DocumentFactDraft
{
    /**
     * @param string $factType      Fact category (e.g. 'lab_result', 'intake_finding', 'referral_reason').
     * @param string $fieldPath     Stable extraction path (e.g. 'results[0]', 'facts[2]').
     * @param string $displayLabel  Human-readable label for evidence display.
     * @param string $factText      Full text representation for persistence and embedding.
     * @param array<string, mixed> $structuredValue Structured JSON payload for persistence.
     * @param DocumentCitation $citation Source citation with provenance.
     * @param Certainty $modelCertainty Model-reported certainty from extraction JSON.
     * @param float $confidence     Model confidence score (0.0–1.0).
     */
    public function __construct(
        public string $factType,
        public string $fieldPath,
        public string $displayLabel,
        public string $factText,
        public array $structuredValue,
        public DocumentCitation $citation,
        public Certainty $modelCertainty,
        public float $confidence,
    ) {
        if (trim($factType) === '') {
            throw new DomainException('Document fact draft requires a non-empty fact type.');
        }
        if (trim($fieldPath) === '') {
            throw new DomainException('Document fact draft requires a non-empty field path.');
        }
        if (trim($factText) === '') {
            throw new DomainException('Document fact draft requires a non-empty fact text.');
        }
        if ($confidence < 0.0 || $confidence > 1.0) {
            throw new DomainException('Document fact draft confidence must be between 0.0 and 1.0.');
        }
    }
}
