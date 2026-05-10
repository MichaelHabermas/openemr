<?php

/**
 * Isolated tests for LocalFileAuditLogTransport.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Tests\Isolated\AgentForge;

use OpenEMR\AgentForge\Observability\LocalFileAuditLogTransport;
use PHPUnit\Framework\TestCase;

final class LocalFileAuditLogTransportTest extends TestCase
{
    private string $tempFile;

    protected function setUp(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'audit_test_');
        self::assertIsString($path);
        $this->tempFile = $path;
    }

    protected function tearDown(): void
    {
        if (file_exists($this->tempFile)) {
            unlink($this->tempFile);
        }
    }

    public function testGrepLinesFindsMatchingEntries(): void
    {
        $lines = [
            '[2026-05-09] agent_forge_request {"decision":"allowed","latency_ms":50}',
            '[2026-05-09] unrelated log line',
            '[2026-05-09] agent_forge_request {"decision":"refused","latency_ms":30}',
        ];
        file_put_contents($this->tempFile, implode("\n", $lines) . "\n");

        $transport = new LocalFileAuditLogTransport();
        $result = $transport->grepLines('agent_forge_request', $this->tempFile, 10);

        self::assertCount(2, $result);
        self::assertStringContainsString('allowed', $result[0]);
        self::assertStringContainsString('refused', $result[1]);
    }

    public function testGrepLinesRespectsMaxLines(): void
    {
        $lines = [];
        for ($i = 0; $i < 5; $i++) {
            $lines[] = sprintf('[2026-05-09] agent_forge_request {"i":%d}', $i);
        }
        file_put_contents($this->tempFile, implode("\n", $lines) . "\n");

        $transport = new LocalFileAuditLogTransport();
        $result = $transport->grepLines('agent_forge_request', $this->tempFile, 2);

        self::assertCount(2, $result);
        self::assertStringContainsString('"i":3', $result[0]);
        self::assertStringContainsString('"i":4', $result[1]);
    }

    public function testGrepLinesReturnsEmptyForNoMatches(): void
    {
        file_put_contents($this->tempFile, "some unrelated log line\n");

        $transport = new LocalFileAuditLogTransport();
        $result = $transport->grepLines('agent_forge_request', $this->tempFile, 10);

        self::assertSame([], $result);
    }
}
