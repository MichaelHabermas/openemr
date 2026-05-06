<?php

/**
 * Deterministic local embedding provider for repeatable guideline evals.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\AgentForge\Guidelines;

final readonly class DeterministicGuidelineEmbeddingProvider implements GuidelineEmbeddingProvider
{
    public const DIMENSIONS = 1536;

    public function embed(string $text): array
    {
        $vector = array_fill(0, self::DIMENSIONS, 0.0);
        foreach (self::tokens($text) as $token) {
            $index = (int) (((int) sprintf('%u', crc32($token))) % self::DIMENSIONS);
            $vector[$index] += 1.0;
        }

        $magnitude = sqrt(array_sum(array_map(static fn (float $value): float => $value * $value, $vector)));
        if ($magnitude <= 0.0) {
            return array_values($vector);
        }

        return array_values(array_map(static fn (float $value): float => round($value / $magnitude, 8), $vector));
    }

    public function modelName(): string
    {
        return 'agentforge-deterministic-hash-1536';
    }

    /** @return list<string> */
    public static function tokens(string $text): array
    {
        preg_match_all('/[a-z0-9]+/i', strtolower($text), $matches);

        $stopWords = [
            'a' => true,
            'an' => true,
            'and' => true,
            'for' => true,
            'guideline' => true,
            'guidelines' => true,
            'in' => true,
            'is' => true,
            'managing' => true,
            'management' => true,
            'of' => true,
            'or' => true,
            'say' => true,
            'the' => true,
            'to' => true,
            'what' => true,
            'with' => true,
        ];

        return array_values(array_unique(array_filter(
            $matches[0],
            static fn (string $token): bool => !isset($stopWords[$token]) && strlen($token) > 1,
        )));
    }
}
