<?php

/**
 * Cohere-backed guideline reranker used only when configured.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\AgentForge\Guidelines;

use RuntimeException;

final readonly class CohereReranker implements GuidelineReranker
{
    public function __construct(
        private string $apiKey,
        private string $model = 'rerank-v3.5',
    ) {
    }

    public static function fromEnvironment(): ?self
    {
        $apiKey = getenv('AGENTFORGE_COHERE_API_KEY');
        if (!is_string($apiKey) || trim($apiKey) === '') {
            return null;
        }

        return new self($apiKey);
    }

    public function rerank(string $query, array $candidates): array
    {
        if ($candidates === []) {
            return [];
        }

        $payload = json_encode([
            'model' => $this->model,
            'query' => $query,
            'documents' => array_map(
                static fn (GuidelineSearchCandidate $candidate): string => $candidate->chunk->chunkText,
                $candidates,
            ),
            'top_n' => count($candidates),
            'return_documents' => false,
        ], JSON_THROW_ON_ERROR);

        $context = stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => [
                    'Authorization: Bearer ' . $this->apiKey,
                    'Content-Type: application/json',
                ],
                'content' => $payload,
                'timeout' => 20,
            ],
        ]);
        $response = file_get_contents('https://api.cohere.com/v2/rerank', false, $context);
        if (!is_string($response)) {
            throw new RuntimeException('Cohere rerank request failed.');
        }

        $decoded = json_decode($response, true, flags: JSON_THROW_ON_ERROR);
        if (!is_array($decoded) || !isset($decoded['results']) || !is_array($decoded['results'])) {
            throw new RuntimeException('Cohere rerank response was not usable.');
        }

        $ranked = [];
        foreach ($decoded['results'] as $result) {
            if (!is_array($result) || !isset($result['index'], $result['relevance_score'])) {
                continue;
            }
            if (!is_int($result['index']) || !is_numeric($result['relevance_score'])) {
                continue;
            }
            $index = $result['index'];
            if (isset($candidates[$index])) {
                $ranked[] = $candidates[$index]->withRerankScore((float) $result['relevance_score']);
            }
        }

        return $ranked === [] ? (new DeterministicReranker())->rerank($query, $candidates) : $ranked;
    }
}
