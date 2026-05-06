<?php

/**
 * Deterministic local embedding provider for tests and offline indexing.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\AgentForge\Document\Embedding;

final readonly class DeterministicEmbeddingProvider implements EmbeddingProvider
{
    public function model(): string
    {
        return 'agentforge-document-deterministic-v1';
    }

    public function embed(string $text): array
    {
        $tokens = preg_split('/[^a-z0-9.]+/', strtolower($text), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $vector = array_fill(0, 1536, 0.0);
        foreach ($tokens as $token) {
            $slot = hexdec(substr(hash('sha256', $token), 0, 8)) % 1536;
            $vector[$slot] += 1.0;
        }

        return array_values($vector);
    }
}
