<?php

/**
 * Embedding provider for guideline retrieval.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\AgentForge\Guidelines;

interface GuidelineEmbeddingProvider
{
    /** @return list<float> */
    public function embed(string $text): array;

    public function modelName(): string;
}
