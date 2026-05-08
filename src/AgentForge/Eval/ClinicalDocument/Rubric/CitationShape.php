<?php

/**
 * Clinical document eval support for AgentForge.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\AgentForge\Eval\ClinicalDocument\Rubric;

use OpenEMR\AgentForge\Document\SourceReview\DocumentCitationNormalizer;
use OpenEMR\AgentForge\StringKeyedArray;

final class CitationShape
{
    /** @var list<string> */
    private const REQUIRED_KEYS = ['source_type', 'source_id', 'page_or_section', 'field_or_chunk_id', 'quote_or_value'];

    public function __construct(private DocumentCitationNormalizer $normalizer = new DocumentCitationNormalizer())
    {
    }

    /** @param array<string, mixed> $citation */
    public function isValid(array $citation): bool
    {
        foreach (self::REQUIRED_KEYS as $key) {
            if (!isset($citation[$key]) || !is_string($citation[$key]) || trim($citation[$key]) === '') {
                return false;
            }
        }

        return $this->normalizer->normalize($citation)->sourceId !== '';
    }

    /** @param array<string, mixed> $fact */
    public function factHasValidCitation(array $fact): bool
    {
        return isset($fact['citation']) && is_array($fact['citation']) && $this->isValid(StringKeyedArray::filter($fact['citation']));
    }

    /** @param array<string, mixed> $fact */
    public function factHasBoundingBox(array $fact): bool
    {
        $box = $fact['bounding_box'] ?? null;
        if ($box === null && isset($fact['citation']) && is_array($fact['citation'])) {
            $citation = StringKeyedArray::filter($fact['citation']);
            $box = $citation['bounding_box'] ?? null;
        }
        if (!is_array($box)) {
            return false;
        }

        return $this->normalizer->boundingBox($box) !== null;
    }
}
