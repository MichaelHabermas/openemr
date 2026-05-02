<?php

/**
 * Seeded SQL evidence eval case definition.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\AgentForge\Eval;

final readonly class SqlEvidenceEvalCase
{
    /**
     * @param list<string> $expectedCitations
     * @param list<string> $expectedMissing
     * @param list<string> $forbiddenCitations
     * @param list<string> $expectedValueFragments
     * @param list<string> $forbiddenValueFragments
     */
    public function __construct(
        public string $id,
        public int $patientId,
        public string $description,
        public array $expectedCitations = [],
        public array $expectedMissing = [],
        public array $forbiddenCitations = [],
        public array $expectedValueFragments = [],
        public array $forbiddenValueFragments = [],
    ) {
    }
}
