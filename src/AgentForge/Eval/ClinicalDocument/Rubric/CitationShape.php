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

use OpenEMR\AgentForge\StringKeyedArray;

final class CitationShape
{
    /** @var list<string> */
    private const REQUIRED_KEYS = ['source_type', 'source_id', 'page_or_section', 'field_or_chunk_id', 'quote_or_value'];

    /** @param array<string, mixed> $citation */
    public function isValid(array $citation): bool
    {
        foreach (self::REQUIRED_KEYS as $key) {
            if (!isset($citation[$key]) || !is_string($citation[$key]) || trim($citation[$key]) === '') {
                return false;
            }
        }

        return true;
    }

    /** @param array<string, mixed> $fact */
    public function factHasValidCitation(array $fact): bool
    {
        return isset($fact['citation']) && is_array($fact['citation']) && $this->isValid(StringKeyedArray::filter($fact['citation']));
    }

    /** @param array<string, mixed> $fact */
    public function factHasBoundingBox(array $fact): bool
    {
        if (!isset($fact['bounding_box']) || !is_array($fact['bounding_box'])) {
            return false;
        }

        foreach (['x', 'y', 'width', 'height'] as $key) {
            if (!isset($fact['bounding_box'][$key]) || !is_numeric($fact['bounding_box'][$key])) {
                return false;
            }
        }

        $width = $fact['bounding_box']['width'];
        $height = $fact['bounding_box']['height'];

        return is_numeric($width) && is_numeric($height) && (float) (string) $width > 0.0 && (float) (string) $height > 0.0;
    }
}
