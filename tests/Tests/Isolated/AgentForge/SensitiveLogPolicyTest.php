<?php

/**
 * Isolated tests for AgentForge sensitive audit log policy.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Tests\Isolated\AgentForge;

use OpenEMR\AgentForge\Observability\SensitiveLogPolicy;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class SensitiveLogPolicyTest extends TestCase
{
    public function testSanitizeContextKeepsOnlyAllowedAuditFields(): void
    {
        $context = SensitiveLogPolicy::sanitizeContext([
            'request_id' => 'request-1',
            'patient_id' => 900001,
            'patient_ref' => 'patient:abcdef1234567890',
            'document_id' => 123,
            'category_id' => 7,
            'doc_type' => 'lab_pdf',
            'job_id' => 9,
            'worker' => 'intake-extractor',
            'attempts' => 0,
            'error_code' => 'RuntimeException',
            'process_id' => 1234,
            'iteration_count' => 2,
            'jobs_processed' => 1,
            'jobs_failed' => 1,
            'lock_token_prefix' => 'abcdef12',
            'idle_seconds' => 5,
            'claimed_count' => 1,
            'worker_status' => 'running',
            'extraction_confidence_min' => 0.91,
            'extraction_confidence_avg' => 0.955,
            'decision' => 'allowed',
            'question' => 'What changed?',
            'answer' => 'raw answer',
            'quote_or_value' => 'LDL 148',
            'exception' => 'SQLSTATE private internals',
            'custom_debug' => 'debug',
        ]);

        $this->assertSame('request-1', $context['request_id']);
        $this->assertArrayNotHasKey('patient_id', $context);
        $this->assertSame('patient:abcdef1234567890', $context['patient_ref']);
        $this->assertSame(123, $context['document_id']);
        $this->assertSame(7, $context['category_id']);
        $this->assertSame('lab_pdf', $context['doc_type']);
        $this->assertSame(9, $context['job_id']);
        $this->assertSame('intake-extractor', $context['worker']);
        $this->assertSame(0, $context['attempts']);
        $this->assertSame('RuntimeException', $context['error_code']);
        $this->assertSame(1234, $context['process_id']);
        $this->assertSame(2, $context['iteration_count']);
        $this->assertSame(1, $context['jobs_processed']);
        $this->assertSame(1, $context['jobs_failed']);
        $this->assertSame('abcdef12', $context['lock_token_prefix']);
        $this->assertSame(5, $context['idle_seconds']);
        $this->assertSame(1, $context['claimed_count']);
        $this->assertSame('running', $context['worker_status']);
        $this->assertSame(0.91, $context['extraction_confidence_min']);
        $this->assertSame(0.955, $context['extraction_confidence_avg']);
        $this->assertSame('allowed', $context['decision']);
        $this->assertArrayNotHasKey('question', $context);
        $this->assertArrayNotHasKey('answer', $context);
        $this->assertArrayNotHasKey('quote_or_value', $context);
        $this->assertArrayNotHasKey('exception', $context);
        $this->assertArrayNotHasKey('custom_debug', $context);
    }

    public function testThrowableErrorContextMergesAllowedKeysAndStripsForbidden(): void
    {
        $e = new RuntimeException('internal');
        $context = SensitiveLogPolicy::throwableErrorContext($e, [
            'document_id' => 42,
            'category_id' => 7,
            'exception' => 'must not leak',
        ]);

        $this->assertSame(RuntimeException::class, $context['error_code']);
        $this->assertSame(42, $context['document_id']);
        $this->assertSame(7, $context['category_id']);
        $this->assertArrayNotHasKey('exception', $context);
    }

    public function testSanitizeContextRecursivelyDropsForbiddenNestedKeys(): void
    {
        $context = SensitiveLogPolicy::sanitizeContext([
            'source_ids' => [
                'document:1',
                ['patient_name' => 'Alice Chen', 'source_id' => 'document:2'],
            ],
            'stage_timings_ms' => [
                'draft' => 25,
                'raw_value' => 'LDL 158',
                'nested' => ['document_text' => 'raw clinical document text', 'verify' => 3],
            ],
            'tools_called' => [
                ['name' => 'Evidence', 'prompt' => 'full prompt must not leak'],
            ],
        ]);

        $this->assertFalse(SensitiveLogPolicy::containsForbiddenKey($context));
        $encoded = json_encode($context, JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString('Alice Chen', $encoded);
        $this->assertStringNotContainsString('LDL 158', $encoded);
        $this->assertStringNotContainsString('raw clinical document text', $encoded);
        $this->assertStringNotContainsString('full prompt must not leak', $encoded);
        $this->assertStringContainsString('document:2', $encoded);
    }

    public function testDetectsForbiddenKeysRecursively(): void
    {
        $this->assertTrue(SensitiveLogPolicy::containsForbiddenKey([
            'nested' => ['full_prompt' => 'raw prompt'],
        ]));
        $this->assertTrue(SensitiveLogPolicy::containsForbiddenKey([
            'citation' => ['quote_or_value' => 'LDL 148'],
        ]));
        $this->assertTrue(SensitiveLogPolicy::containsForbiddenKey([
            'document' => ['extracted_fields' => ['allergies' => 'sulfa']],
        ]));
        $this->assertFalse(SensitiveLogPolicy::containsForbiddenKey([
            'decision' => 'allowed',
            'source_ids' => ['lab:procedure_result/a1c@2026-04-10'],
        ]));
    }
}
