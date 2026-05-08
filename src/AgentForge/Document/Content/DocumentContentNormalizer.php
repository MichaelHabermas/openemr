<?php

/**
 * Boundary for turning raw OpenEMR document bytes into provider-ready content.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\AgentForge\Document\Content;

use OpenEMR\AgentForge\Deadline;

interface DocumentContentNormalizer
{
    public function supports(DocumentContentNormalizationRequest $request): bool;

    public function normalize(
        DocumentContentNormalizationRequest $request,
        Deadline $deadline,
    ): NormalizedDocumentContent;

    public function name(): string;
}
