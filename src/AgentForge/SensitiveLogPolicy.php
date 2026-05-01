<?php

/**
 * PHI-minimizing sensitive audit log policy for AgentForge.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\AgentForge;

final class SensitiveLogPolicy
{
    /** @var array<string, true> */
    private const ALLOWED_KEYS = [
        'request_id' => true,
        'user_id' => true,
        'patient_id' => true,
        'decision' => true,
        'latency_ms' => true,
        'timestamp' => true,
        'question_type' => true,
        'tools_called' => true,
        'source_ids' => true,
        'model' => true,
        'input_tokens' => true,
        'output_tokens' => true,
        'estimated_cost' => true,
        'failure_reason' => true,
        'verifier_result' => true,
    ];

    /** @var array<string, true> */
    private const FORBIDDEN_KEYS = [
        'question' => true,
        'answer' => true,
        'patient_name' => true,
        'full_prompt' => true,
        'prompt' => true,
        'chart_text' => true,
        'exception' => true,
        'raw_exception' => true,
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
            $sanitized[$key] = $value;
        }

        return $sanitized;
    }

    /** @param array<string, mixed> $context */
    public static function containsForbiddenKey(array $context): bool
    {
        foreach ($context as $key => $value) {
            if (isset(self::FORBIDDEN_KEYS[$key])) {
                return true;
            }
            if (is_array($value) && self::containsForbiddenKey(StringKeyedArray::filter($value))) {
                return true;
            }
        }

        return false;
    }
}
