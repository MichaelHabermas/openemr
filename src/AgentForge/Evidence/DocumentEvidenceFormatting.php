<?php

/**
 * Shared formatting helpers for document-based evidence tools.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\AgentForge\Evidence;

use DomainException;
use OpenEMR\AgentForge\Document\SourceReview\DocumentCitationNormalizer;

final class DocumentEvidenceFormatting
{
    /** @param array<string, mixed> $row */
    public static function string(array $row, string $key): string
    {
        $value = $row[$key] ?? '';
        return is_scalar($value) ? trim((string) $value) : '';
    }

    /** @param array<string, mixed> $row */
    public static function positiveInt(array $row, string $key): int
    {
        $value = $row[$key] ?? null;
        if (is_int($value) && $value > 0) {
            return $value;
        }
        if (is_string($value) && ctype_digit($value) && (int) $value > 0) {
            return (int) $value;
        }

        throw new DomainException(sprintf('Evidence row %s must be a positive integer.', $key));
    }

    public static function evidenceCitationSuffix(string $docType, string $page, string $field): string
    {
        return sprintf('Citation: %s, %s, %s', $docType, $page, $field);
    }

    public static function sourceDate(string ...$candidates): string
    {
        foreach ($candidates as $candidate) {
            if (preg_match('/^\d{4}-\d{2}-\d{2}/', $candidate, $matches) === 1) {
                return $matches[0];
            }
        }

        return 'unknown';
    }

    /** @return array{x: float, y: float, width: float, height: float}|null */
    public static function normalizedBoundingBox(mixed $value): ?array
    {
        return (new DocumentCitationNormalizer())->boundingBox($value);
    }
}
