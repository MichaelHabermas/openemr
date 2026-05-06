<?php

/**
 * Value object capturing all fields for a single promotion ledger entry.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\AgentForge\Document\Promotion;

use OpenEMR\AgentForge\Document\DocumentJob;
use OpenEMR\AgentForge\Document\Schema\DocumentCitation;

final readonly class PromotionRecord
{
    /**
     * @param array<string, mixed> $valueJson
     */
    public function __construct(
        public DocumentJob $job,
        public string $factType,
        public string $fieldPath,
        public string $label,
        public array $valueJson,
        public DocumentCitation $citation,
        public PromotionOutcome $outcome,
        public ?string $promotedTable,
        public ?string $promotedRecordId,
        public ?string $promotedPkJson,
        public string $factFingerprint,
        public string $clinicalContentFingerprint,
        public ?float $confidence,
        public ?string $conflictReason = null,
    ) {
    }
}
