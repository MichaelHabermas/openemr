<?php

/**
 * Resolves document hook collaborators without exposing test seams on dispatch().
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\AgentForge\Document;

use OpenEMR\AgentForge\SqlQueryUtilsExecutor;

final class DocumentHookServiceBinding
{
    private static ?DocumentJobRepository $jobRepositoryOverride = null;

    private static ?DocumentUploadEnqueuer $uploadEnqueuerOverride = null;

    public static function jobRepository(): DocumentJobRepository
    {
        return self::$jobRepositoryOverride ?? new SqlDocumentJobRepository(new SqlQueryUtilsExecutor());
    }

    public static function uploadEnqueuer(): DocumentUploadEnqueuer
    {
        return self::$uploadEnqueuerOverride ?? DocumentUploadEnqueuerFactory::createDefault();
    }

    /**
     * @internal
     */
    public static function resetForTesting(): void
    {
        self::$jobRepositoryOverride = null;
        self::$uploadEnqueuerOverride = null;
    }

    /**
     * @internal
     */
    public static function setJobRepositoryForTesting(?DocumentJobRepository $repository): void
    {
        self::$jobRepositoryOverride = $repository;
    }

    /**
     * @internal
     */
    public static function setUploadEnqueuerForTesting(?DocumentUploadEnqueuer $enqueuer): void
    {
        self::$uploadEnqueuerOverride = $enqueuer;
    }
}
