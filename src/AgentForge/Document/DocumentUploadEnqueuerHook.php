<?php

/**
 * Safe procedural upload hook wrapper for AgentForge document enqueue.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\AgentForge\Document;

use OpenEMR\AgentForge\Auth\PatientId;

final class DocumentUploadEnqueuerHook
{
    public static function dispatch(mixed $patientId, mixed $categoryId, mixed $result): void
    {
        DocumentProceduralHookGuard::runThenLogFailures(
            'clinical_document.job.hook_failed',
            static function () use ($patientId, $categoryId, $result): void {
                if (!is_array($result) || !isset($result['doc_id'])) {
                    return;
                }

                $documentId = StrictPositiveInt::tryParse($result['doc_id']);
                $patientIdParsed = StrictPositiveInt::tryParse($patientId);
                $categoryIdParsed = StrictPositiveInt::tryParse($categoryId);
                if ($documentId === null || $patientIdParsed === null || $categoryIdParsed === null) {
                    return;
                }

                DocumentHookServiceBinding::uploadEnqueuer()->enqueueIfEligible(
                    new PatientId($patientIdParsed),
                    new DocumentId($documentId),
                    new CategoryId($categoryIdParsed),
                );
            },
        );
    }
}
