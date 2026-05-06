<?php

/**
 * Structured, sanitized log context for document extraction completion (worker + eval parity).
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/open-emr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\AgentForge\Observability;

use OpenEMR\AgentForge\Document\DocumentJob;
use OpenEMR\AgentForge\Document\Extraction\ExtractionProviderResponse;
use OpenEMR\AgentForge\Orchestration\NodeName;

final class DocumentExtractionLogContext
{
    /**
     * @param array{verified: int, document_fact: int, needs_review: int} $factCounts
     *
     * @return array<string, mixed>
     */
    public static function intakeExtractionCompleted(
        NodeName $nodeName,
        DocumentJob $job,
        ExtractionProviderResponse $response,
        PatientRefHasher $patientRefHasher,
        array $factCounts,
    ): array {
        return SensitiveLogPolicy::sanitizeContext([
            'worker' => $nodeName->value,
            'job_id' => $job->id?->value,
            'document_id' => $job->documentId->value,
            'patient_ref' => $patientRefHasher->hash($job->patientId),
            'doc_type' => $job->docType->value,
            'extraction_provider' => $response->model ?? $response->usage->model,
            'model' => $response->model ?? $response->usage->model,
            'input_tokens' => $response->usage->inputTokens,
            'output_tokens' => $response->usage->outputTokens,
            'estimated_cost' => $response->usage->estimatedCost,
            'fact_count_verified' => $factCounts['verified'],
            'fact_count_document_fact' => $factCounts['document_fact'],
            'fact_count_needs_review' => $factCounts['needs_review'],
            'schema_valid' => $response->schemaValid,
            'status' => 'succeeded',
        ]);
    }
}
