<?php

/**
 * Safe procedural delete hook wrapper for AgentForge document job retraction.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\AgentForge\Document;

use OpenEMR\AgentForge\Observability\SensitiveLogPolicy;
use OpenEMR\BC\ServiceContainer;

final class DocumentRetractionHook
{
    /**
     * @param mixed $documentId
     * @param null|callable(): DocumentJobRepository $repositoryResolver
     */
    public static function dispatch(mixed $documentId, ?callable $repositoryResolver = null): void
    {
        try {
            $documentId = filter_var($documentId, FILTER_VALIDATE_INT);
            if ($documentId === false) {
                return;
            }

            $documentId = new DocumentId((int) $documentId);
            $resolver = $repositoryResolver ?? static fn (): DocumentJobRepository => new SqlDocumentJobRepository();
            $retracted = $resolver()->retractByDocument(
                $documentId,
                DocumentRetractionReason::SourceDocumentDeleted,
            );

            ServiceContainer::getLogger()->info(
                'clinical_document.jobs.retracted',
                SensitiveLogPolicy::sanitizeContext([
                    'document_id' => $documentId->value,
                    'status' => JobStatus::Retracted->value,
                    'retraction_reason' => DocumentRetractionReason::SourceDocumentDeleted->value,
                    'count' => $retracted,
                ]),
            );
        } catch (\Throwable $e) {
            ServiceContainer::getLogger()->error(
                'clinical_document.retraction_hook_failed',
                SensitiveLogPolicy::sanitizeContext(['error_code' => $e::class]),
            );
        }
    }
}
