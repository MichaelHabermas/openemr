<?php

/**
 * Isolated tests for AuditLogEntryParser JSON extraction.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Tests\Isolated\AgentForge;

use OpenEMR\AgentForge\Observability\AuditLogEntryParser;
use PHPUnit\Framework\TestCase;

final class AuditLogEntryParserTest extends TestCase
{
    public function testExtractsJsonFromCleanLine(): void
    {
        $json = json_encode(['decision' => 'allowed', 'latency_ms' => 42], JSON_THROW_ON_ERROR);
        $result = AuditLogEntryParser::extractFields($json);

        self::assertSame('allowed', $result['decision']);
        self::assertSame(42, $result['latency_ms']);
    }

    public function testExtractsJsonWithPsr3Prefix(): void
    {
        $json = json_encode(['request_id' => 'abc-123', 'model' => 'gpt-4o'], JSON_THROW_ON_ERROR);
        $line = '[2026-05-09T12:00:00+00:00] openemr.INFO: agent_forge_request ' . $json;
        $result = AuditLogEntryParser::extractFields($line);

        self::assertSame('abc-123', $result['request_id']);
        self::assertSame('gpt-4o', $result['model']);
    }

    public function testExtractsJsonWithTrailingGarbage(): void
    {
        $json = json_encode(['decision' => 'refused'], JSON_THROW_ON_ERROR);
        $line = 'prefix ' . $json . ' [] []';
        $result = AuditLogEntryParser::extractFields($line);

        self::assertSame('refused', $result['decision']);
    }

    public function testReturnsEmptyArrayForNoJson(): void
    {
        self::assertSame([], AuditLogEntryParser::extractFields('no json here'));
    }

    public function testReturnsEmptyArrayForEmptyString(): void
    {
        self::assertSame([], AuditLogEntryParser::extractFields(''));
    }
}
