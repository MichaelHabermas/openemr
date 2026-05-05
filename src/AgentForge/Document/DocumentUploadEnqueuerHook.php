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
use OpenEMR\AgentForge\Observability\SensitiveLogPolicy;
use OpenEMR\BC\ServiceContainer;
use RuntimeException;

final class DocumentUploadEnqueuerHook
{
    /**
     * @param mixed $patientId
     * @param mixed $categoryId
     * @param mixed $result
     * @param null|callable(): mixed $enqueuerResolver
     */
    public static function dispatch(
        mixed $patientId,
        mixed $categoryId,
        mixed $result,
        ?callable $enqueuerResolver = null,
    ): void {
        try {
            if (!is_array($result) || !array_key_exists('doc_id', $result)) {
                return;
            }

            $documentId = filter_var($result['doc_id'], FILTER_VALIDATE_INT);
            $patientId = filter_var($patientId, FILTER_VALIDATE_INT);
            $categoryId = filter_var($categoryId, FILTER_VALIDATE_INT);
            if ($documentId === false || $patientId === false || $categoryId === false) {
                return;
            }

            $resolver = $enqueuerResolver ?? DocumentUploadEnqueuerFactory::createDefault(...);
            $enqueuer = $resolver();
            if (!$enqueuer instanceof DocumentUploadEnqueuer) {
                throw new RuntimeException('Document upload enqueuer resolver returned an invalid service.');
            }

            $enqueuer->enqueueIfEligible(
                new PatientId((int) $patientId),
                new DocumentId((int) $documentId),
                new CategoryId((int) $categoryId),
            );
        } catch (\Throwable $e) {
            ServiceContainer::getLogger()->error(
                'clinical_document.job.hook_failed',
                SensitiveLogPolicy::sanitizeContext(['error_code' => $e::class]),
            );
        }
    }
}
