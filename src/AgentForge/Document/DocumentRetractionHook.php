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
    public static function dispatch(mixed $documentId): void
    {
        DocumentProceduralHookGuard::runThenLogFailures(
            'clinical_document.retraction_hook_failed',
            static function () use ($documentId): void {
                $id = StrictPositiveInt::tryParse($documentId);
                if ($id === null) {
                    return;
                }

                $documentIdVo = new DocumentId($id);
                $retracted = DocumentHookServiceBinding::jobRepository()->retractByDocument(
                    $documentIdVo,
                    DocumentRetractionReason::SourceDocumentDeleted,
                );

                ServiceContainer::getLogger()->info(
                    'clinical_document.jobs.retracted',
                    SensitiveLogPolicy::sanitizeContext([
                        'document_id' => $documentIdVo->value,
                        'status' => JobStatus::Retracted->value,
                        'retraction_reason' => DocumentRetractionReason::SourceDocumentDeleted->value,
                        'count' => $retracted,
                    ]),
                );
            },
        );
    }
}
