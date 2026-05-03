<?php

/**
 * Overwrites a stable per-tier Markdown summary next to the eval JSON output.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\AgentForge\Reporting;

use DateTimeImmutable;
use DateTimeInterface;
use Throwable;

final class EvalLatestSummaryWriter
{
    public const FILENAME_TIER0 = 'LATEST-SUMMARY-TIER0.md';

    public const FILENAME_TIER1 = 'LATEST-SUMMARY-TIER1.md';

    public const FILENAME_TIER2 = 'LATEST-SUMMARY-TIER2.md';

    public const FILENAME_TIER4 = 'LATEST-SUMMARY-TIER4.md';

    /**
     * Reads eval JSON at $jsonPath and overwrites the tier's latest Markdown in the same directory.
     * Never throws to callers; failures go to STDERR. Skipped when AGENTFORGE_SKIP_LATEST_SUMMARY is non-empty.
     */
    public static function tryWriteFromEvalJsonFile(string $jsonPath): void
    {
        $skip = getenv('AGENTFORGE_SKIP_LATEST_SUMMARY');
        if ($skip !== false && $skip !== '') {
            return;
        }

        try {
            if (!is_file($jsonPath) || !is_readable($jsonPath)) {
                fwrite(STDERR, sprintf("AgentForge latest summary: skip (not readable): %s\n", $jsonPath));

                return;
            }

            $raw = file_get_contents($jsonPath);
            if ($raw === false) {
                fwrite(STDERR, sprintf("AgentForge latest summary: skip (read failed): %s\n", $jsonPath));

                return;
            }

            /** @var array<string, mixed> $json */
            $json = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
            $run = (new EvalResultNormalizer())->fromDecodedJson($json);
            $markdown = (new MarkdownEvalSummaryRenderer())->render($run);
            $basename = self::filenameForTierKey($run->tierKey);
            $outPath = rtrim(dirname($jsonPath), '/') . '/' . $basename;
            $banner = self::buildBannerLine($jsonPath);
            $payload = $banner . "\n" . $markdown;

            if (file_put_contents($outPath, $payload) === false) {
                fwrite(STDERR, sprintf("AgentForge latest summary: write failed: %s\n", $outPath));
            }
        } catch (Throwable $e) {
            fwrite(STDERR, sprintf("AgentForge latest summary: skipped (%s)\n", $e->getMessage()));
        }
    }

    public static function filenameForTierKey(string $tierKey): string
    {
        return match ($tierKey) {
            'tier0_fixture' => self::FILENAME_TIER0,
            'tier1_sql_evidence' => self::FILENAME_TIER1,
            'tier2_live_model' => self::FILENAME_TIER2,
            'tier4_deployed_smoke' => self::FILENAME_TIER4,
            default => throw new \InvalidArgumentException('Unknown tier key for latest summary: ' . $tierKey),
        };
    }

    private static function buildBannerLine(string $jsonPath): string
    {
        $base = basename($jsonPath);
        $utc = (new DateTimeImmutable('now', new \DateTimeZone('UTC')))->format(DateTimeInterface::ATOM);

        return sprintf('<!-- Generated from %s at %s -->', $base, $utc);
    }
}
