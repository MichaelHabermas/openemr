<?php

/**
 * Strict field readers shared by AgentForge document extraction schemas.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\AgentForge\Document\Schema;

/**
 * @internal
 */
final class SchemaReader
{
    /**
     * @param array<string, mixed> $data
     */
    public static function requiredString(array $data, string $field, string $path): string
    {
        $value = self::required($data, $field, $path);
        if (!is_string($value) || $value === '') {
            throw new ExtractionSchemaException(self::join($path, $field), 'Expected non-empty string.');
        }

        return $value;
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function optionalString(array $data, string $field, string $path): ?string
    {
        if (!array_key_exists($field, $data) || $data[$field] === null) {
            return null;
        }

        return self::requiredString($data, $field, $path);
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function requiredFloat(array $data, string $field, string $path): float
    {
        $value = self::required($data, $field, $path);
        if (!is_int($value) && !is_float($value)) {
            throw new ExtractionSchemaException(self::join($path, $field), 'Expected number.');
        }

        return (float) $value;
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function requiredConfidence(array $data, string $field, string $path): float
    {
        $value = self::requiredFloat($data, $field, $path);
        if ($value < 0.0 || $value > 1.0) {
            throw new ExtractionSchemaException(self::join($path, $field), 'Expected number between 0 and 1.');
        }

        return $value;
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public static function requiredObject(array $data, string $field, string $path): array
    {
        $value = self::required($data, $field, $path);
        if (!is_array($value) || array_is_list($value)) {
            throw new ExtractionSchemaException(self::join($path, $field), 'Expected object.');
        }

        /** @var array<string, mixed> $value */
        return $value;
    }

    /**
     * @param array<string, mixed> $data
     * @return list<mixed>
     */
    public static function requiredList(array $data, string $field, string $path): array
    {
        $value = self::required($data, $field, $path);
        if (!is_array($value) || !array_is_list($value)) {
            throw new ExtractionSchemaException(self::join($path, $field), 'Expected list.');
        }

        return $value;
    }

    /**
     * @param array<string, mixed> $data
     * @return list<mixed>
     */
    public static function optionalList(array $data, string $field, string $path): array
    {
        if (!array_key_exists($field, $data) || $data[$field] === null) {
            return [];
        }

        $value = $data[$field];
        if (!is_array($value) || !array_is_list($value)) {
            throw new ExtractionSchemaException(self::join($path, $field), 'Expected list.');
        }

        return $value;
    }

    /**
     * @param array<string, mixed> $data
     * @param list<string> $allowedFields
     */
    public static function assertNoUnknownFields(array $data, array $allowedFields, string $path): void
    {
        foreach (array_keys($data) as $field) {
            if (!in_array($field, $allowedFields, true)) {
                throw new ExtractionSchemaException(self::join($path, $field), 'Unknown field.');
            }
        }
    }

    public static function assertDocumentType(string $actual, string $expected, string $path): void
    {
        if ($actual !== $expected) {
            throw new ExtractionSchemaException($path, "Expected document type {$expected}.");
        }
    }

    public static function join(string $path, string $field): string
    {
        return $path === '' ? $field : "{$path}.{$field}";
    }

    public static function index(string $path, int $index): string
    {
        return "{$path}[{$index}]";
    }

    /**
     * @param array<string, mixed> $data
     */
    private static function required(array $data, string $field, string $path): mixed
    {
        if (!array_key_exists($field, $data)) {
            throw new ExtractionSchemaException(self::join($path, $field), 'Missing required field.');
        }

        return $data[$field];
    }
}
