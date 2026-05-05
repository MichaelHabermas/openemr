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
            'decision' => 'allowed',
            'question' => 'What changed?',
            'answer' => 'raw answer',
            'quote_or_value' => 'LDL 148',
            'exception' => 'SQLSTATE private internals',
            'custom_debug' => 'debug',
        ]);

        $this->assertSame('request-1', $context['request_id']);
        $this->assertSame(900001, $context['patient_id']);
        $this->assertSame('patient:abcdef1234567890', $context['patient_ref']);
        $this->assertSame(123, $context['document_id']);
        $this->assertSame(7, $context['category_id']);
        $this->assertSame('lab_pdf', $context['doc_type']);
        $this->assertSame(9, $context['job_id']);
        $this->assertSame('intake-extractor', $context['worker']);
        $this->assertSame(0, $context['attempts']);
        $this->assertSame('RuntimeException', $context['error_code']);
        $this->assertSame('allowed', $context['decision']);
        $this->assertArrayNotHasKey('question', $context);
        $this->assertArrayNotHasKey('answer', $context);
        $this->assertArrayNotHasKey('quote_or_value', $context);
        $this->assertArrayNotHasKey('exception', $context);
        $this->assertArrayNotHasKey('custom_debug', $context);
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
