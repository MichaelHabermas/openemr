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
     * @param array<string, int> $stageTimingsMs
     *
     * @return array<string, mixed>
     */
    public static function intakeExtractionCompleted(
        NodeName $nodeName,
        DocumentJob $job,
        ExtractionProviderResponse $response,
        PatientRefHasher $patientRefHasher,
        array $factCounts,
        array $stageTimingsMs = [],
    ): array {
        return SensitiveLogPolicy::sanitizeContext(array_merge([
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
            'extraction_confidence_min' => self::confidenceMin($response->facts),
            'extraction_confidence_avg' => self::confidenceAvg($response->facts),
            'facts_extracted_count' => array_sum($factCounts),
            'facts_promoted_count' => $factCounts['verified'],
            'facts_needing_review_count' => $factCounts['needs_review'],
            'schema_valid' => $response->schemaValid,
            'stage_timings_ms' => $stageTimingsMs,
            'status' => 'succeeded',
        ], $response->normalizationTelemetry));
    }

    /** @param list<array<string, mixed>> $facts */
    private static function confidenceMin(array $facts): ?float
    {
        $values = self::confidenceValues($facts);

        return $values === [] ? null : min($values);
    }

    /** @param list<array<string, mixed>> $facts */
    private static function confidenceAvg(array $facts): ?float
    {
        $values = self::confidenceValues($facts);

        return $values === [] ? null : round(array_sum($values) / count($values), 6);
    }

    /**
     * @param list<array<string, mixed>> $facts
     * @return list<float>
     */
    private static function confidenceValues(array $facts): array
    {
        $values = [];
        foreach ($facts as $fact) {
            if (is_int($fact['confidence'] ?? null) || is_float($fact['confidence'] ?? null)) {
                $values[] = (float) $fact['confidence'];
            }
        }

        return $values;
    }
}
