<?php

/**
 * Placeholder boundary for live OpenAI document fact embeddings.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\AgentForge\Document\Embedding;

use RuntimeException;

final readonly class OpenAiEmbeddingProvider implements EmbeddingProvider
{
    public function __construct(private string $modelName = 'text-embedding-3-small')
    {
    }

    public function model(): string
    {
        return $this->modelName;
    }

    public function embed(string $text): array
    {
        throw new RuntimeException('Live document fact embeddings are not configured in this runtime.');
    }
}
