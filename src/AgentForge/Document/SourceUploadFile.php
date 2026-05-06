<?php

/**
 * Shared validation for uploaded source files on disk (attach path and storage backends).
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/open-emr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\AgentForge\Document;

use RuntimeException;

final class SourceUploadFile
{
    public static function isReadableNonEmptyFile(string $filePath): bool
    {
        if (!is_file($filePath) || !is_readable($filePath)) {
            return false;
        }

        $bytes = @file_get_contents($filePath);

        return is_string($bytes) && $bytes !== '';
    }

    /**
     * @throws RuntimeException When the file is missing, unreadable, or empty.
     */
    public static function readBytesOrThrow(string $filePath): string
    {
        if (!is_file($filePath) || !is_readable($filePath)) {
            throw new RuntimeException('Source document file is missing or unreadable.');
        }

        $bytes = file_get_contents($filePath);
        if (!is_string($bytes) || $bytes === '') {
            throw new RuntimeException('Source document file could not be read.');
        }

        return $bytes;
    }
}
