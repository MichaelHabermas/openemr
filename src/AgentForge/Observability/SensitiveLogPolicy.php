<?php

/**
 * PHI-minimizing sensitive audit log policy for AgentForge.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\AgentForge\Observability;

final class SensitiveLogPolicy
{
    /** @var array<string, true> */
    private const ALLOWED_KEYS = [
        'request_id' => true,
        'user_id' => true,
        'decision' => true,
        'latency_ms' => true,
        'timestamp' => true,
        'conversation_id' => true,
        'question_type' => true,
        'tools_called' => true,
        'skipped_chart_sections' => true,
        'source_ids' => true,
        'model' => true,
        'input_tokens' => true,
        'output_tokens' => true,
        'estimated_cost' => true,
        'failure_reason' => true,
        'verifier_result' => true,
        'stage_timings_ms' => true,
        'selector_mode' => true,
        'selector_result' => true,
        'selector_fallback_reason' => true,
        'patient_ref' => true,
        'document_id' => true,
        'category_id' => true,
        'doc_type' => true,
        'job_id' => true,
        'worker' => true,
        'status' => true,
        'attempts' => true,
        'count' => true,
        'error_code' => true,
        'retraction_reason' => true,
        'process_id' => true,
        'iteration_count' => true,
        'jobs_processed' => true,
        'jobs_failed' => true,
        'lock_token_prefix' => true,
        'idle_seconds' => true,
        'claimed_count' => true,
        'worker_status' => true,
        'extraction_provider' => true,
        'fact_count_verified' => true,
        'fact_count_document_fact' => true,
        'fact_count_needs_review' => true,
        'extraction_confidence_min' => true,
        'extraction_confidence_avg' => true,
        'schema_valid' => true,
        'pages_rendered' => true,
        'normalizer' => true,
        'source_mime_type' => true,
        'source_byte_count' => true,
        'rendered_page_count' => true,
        'text_section_count' => true,
        'table_count' => true,
        'message_segment_count' => true,
        'warning_codes' => true,
        'normalization_elapsed_ms' => true,
        'http_status' => true,
        'event' => true,
        'case_id' => true,
        'fact_count' => true,
        'citation_count' => true,
        'model_calls' => true,
        'facts_extracted_count' => true,
        'facts_promoted_count' => true,
        'facts_needing_review_count' => true,
        'promoted' => true,
        'needs_review' => true,
        'skipped' => true,
    ];

    /** @var array<string, true> */
    private const FORBIDDEN_KEYS = [
        'question' => true,
        'answer' => true,
        'patient_name' => true,
        'quote' => true,
        'quote_or_value' => true,
        'raw_quote' => true,
        'raw_value' => true,
        'full_prompt' => true,
        'prompt' => true,
        'chart_text' => true,
        'document_text' => true,
        'document_image' => true,
        'image_data_url' => true,
        'rendered_image_bytes' => true,
        'raw_hl7' => true,
        'spreadsheet_cells' => true,
        'extracted_fields' => true,
        'exception' => true,
        'raw_exception' => true,
        'patient_id' => true,
    ];

    /**
     * @param array<string, mixed> $context
     * @return array<string, mixed>
     */
    public static function sanitizeContext(array $context): array
    {
        $sanitized = [];
        foreach ($context as $key => $value) {
            if (isset(self::FORBIDDEN_KEYS[$key]) || !isset(self::ALLOWED_KEYS[$key])) {
                continue;
            }
            $sanitized[$key] = self::sanitizeValue($value);
        }

        return $sanitized;
    }

    private static function sanitizeValue(mixed $value): mixed
    {
        if (!is_array($value)) {
            return $value;
        }

        $sanitized = [];
        foreach ($value as $key => $nestedValue) {
            if (is_string($key) && isset(self::FORBIDDEN_KEYS[$key])) {
                continue;
            }
            $sanitized[$key] = self::sanitizeValue($nestedValue);
        }

        return $sanitized;
    }

    /**
     * Merge throwable class name with extra context, then apply {@see sanitizeContext()}.
     *
     * @param array<string, mixed> $extra
     * @return array<string, mixed>
     */
    public static function throwableErrorContext(\Throwable $e, array $extra = []): array
    {
        return self::sanitizeContext(array_merge($extra, ['error_code' => $e::class]));
    }

    /** @param array<mixed> $context */
    public static function containsForbiddenKey(array $context): bool
    {
        foreach ($context as $key => $value) {
            if (isset(self::FORBIDDEN_KEYS[$key])) {
                return true;
            }
            if (is_array($value) && self::containsForbiddenKey($value)) {
                return true;
            }
        }

        return false;
    }
}
