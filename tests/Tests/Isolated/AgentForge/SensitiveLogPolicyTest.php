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
            'decision' => 'allowed',
            'question' => 'What changed?',
            'answer' => 'raw answer',
            'exception' => 'SQLSTATE private internals',
            'custom_debug' => 'debug',
        ]);

        $this->assertSame('request-1', $context['request_id']);
        $this->assertSame(900001, $context['patient_id']);
        $this->assertSame('allowed', $context['decision']);
        $this->assertArrayNotHasKey('question', $context);
        $this->assertArrayNotHasKey('answer', $context);
        $this->assertArrayNotHasKey('exception', $context);
        $this->assertArrayNotHasKey('custom_debug', $context);
    }

    public function testDetectsForbiddenKeysRecursively(): void
    {
        $this->assertTrue(SensitiveLogPolicy::containsForbiddenKey([
            'nested' => ['full_prompt' => 'raw prompt'],
        ]));
        $this->assertFalse(SensitiveLogPolicy::containsForbiddenKey([
            'decision' => 'allowed',
            'source_ids' => ['lab:procedure_result/a1c@2026-04-10'],
        ]));
    }
}
