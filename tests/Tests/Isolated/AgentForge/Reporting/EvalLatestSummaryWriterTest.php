<?php

/**
 * Isolated tests for EvalLatestSummaryWriter.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Tests\Isolated\AgentForge\Reporting;

use OpenEMR\AgentForge\Reporting\EvalLatestSummaryWriter;
use PHPUnit\Framework\TestCase;

final class EvalLatestSummaryWriterTest extends TestCase
{
    public function testTryWriteCreatesLatestTier0Markdown(): void
    {
        $dir = sys_get_temp_dir() . '/agentforge-latest-summary-test-' . bin2hex(random_bytes(4));
        self::assertTrue(mkdir($dir, 0700, true));

        $jsonPath = $dir . '/eval-results-test.json';
        try {
            $tier0Json = [
                'fixture_version' => 'test-fixture',
                'timestamp' => '2026-05-02T12:00:00+00:00',
                'code_version' => 'abc',
                'total' => 1,
                'passed' => 1,
                'failed' => 0,
                'safety_failure' => false,
                'results' => [
                    [
                        'id' => 'latest_writer_probe',
                        'passed' => true,
                        'failure_reason' => '',
                        'status' => 'ok',
                        'log_context' => [],
                    ],
                ],
            ];
            file_put_contents($jsonPath, json_encode($tier0Json, JSON_THROW_ON_ERROR));

            EvalLatestSummaryWriter::tryWriteFromEvalJsonFile($jsonPath);

            $mdPath = $dir . '/' . EvalLatestSummaryWriter::FILENAME_TIER0;
            self::assertFileExists($mdPath);
            $content = (string) file_get_contents($mdPath);
            self::assertStringContainsString('<!-- Generated from eval-results-test.json', $content);
            self::assertStringContainsString('Tier 0', $content);
            self::assertStringContainsString('latest_writer_probe', $content);
        } finally {
            @unlink($dir . '/' . EvalLatestSummaryWriter::FILENAME_TIER0);
            @unlink($jsonPath);
            @rmdir($dir);
        }
    }

    public function testSkipLatestSummaryWhenEnvSet(): void
    {
        $prior = getenv('AGENTFORGE_SKIP_LATEST_SUMMARY');
        self::assertTrue(putenv('AGENTFORGE_SKIP_LATEST_SUMMARY=1'));

        try {
            $dir = sys_get_temp_dir() . '/agentforge-latest-skip-' . bin2hex(random_bytes(4));
            self::assertTrue(mkdir($dir, 0700, true));

            try {
                $jsonPath = $dir . '/eval-skip.json';
                file_put_contents($jsonPath, '{"invalid": true}');
                EvalLatestSummaryWriter::tryWriteFromEvalJsonFile($jsonPath);
                self::assertFileDoesNotExist($dir . '/' . EvalLatestSummaryWriter::FILENAME_TIER0);
            } finally {
                @unlink($dir . '/' . EvalLatestSummaryWriter::FILENAME_TIER0);
                @unlink($dir . '/eval-skip.json');
                @rmdir($dir);
            }
        } finally {
            if ($prior === false) {
                putenv('AGENTFORGE_SKIP_LATEST_SUMMARY');
            } else {
                putenv('AGENTFORGE_SKIP_LATEST_SUMMARY=' . $prior);
            }
        }
    }

    public function testFilenameForTierKey(): void
    {
        self::assertSame(EvalLatestSummaryWriter::FILENAME_TIER0, EvalLatestSummaryWriter::filenameForTierKey('tier0_fixture'));
        self::assertSame(EvalLatestSummaryWriter::FILENAME_TIER1, EvalLatestSummaryWriter::filenameForTierKey('tier1_sql_evidence'));
        self::assertSame(EvalLatestSummaryWriter::FILENAME_TIER2, EvalLatestSummaryWriter::filenameForTierKey('tier2_live_model'));
        self::assertSame(EvalLatestSummaryWriter::FILENAME_TIER4, EvalLatestSummaryWriter::filenameForTierKey('tier4_deployed_smoke'));
    }

    public function testFilenameForTierKeyUnknownThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        EvalLatestSummaryWriter::filenameForTierKey('unknown_tier');
    }
}
